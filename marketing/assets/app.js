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

function toast(m, kind = "") {
  const n = document.createElement("div");
  const err = kind === "err" || kind === "error" || /impossible|invalide|erreur|introuvable|obligatoire|déjà|au maximum|existe|expiré/i.test(m);
  n.className = "toast" + (err ? " err" : "");
  n.innerHTML = ic(err ? "alert-c" : "check") + `<span>${esc(m)}</span>`;
  document.body.append(n);
  setTimeout(() => n.remove(), 3400);
}

/* ================================================================ icônes au trait (24 × 24) et couleurs par domaine */
const ICONS = {
  home: "M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z",
  megaphone: "M3 11v2a1 1 0 0 0 1 1h2l6 4V6L6 10H4a1 1 0 0 0-1 1zM16 8.5a5 5 0 0 1 0 7M19 5.5a9 9 0 0 1 0 13",
  users: "M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 20v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8",
  "user-plus": "M15 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M8.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM19 8v6M22 11h-6",
  funnel: "M3 4h18l-7 8.5V19l-4 2v-8.5z",
  share: "M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4",
  target: "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z",
  globe: "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20",
  mail: "M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zM3 7l9 6 9-6",
  folder: "M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z",
  settings: "M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z",
  search: "M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3",
  bell: "M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0",
  plus: "M12 5v14M5 12h14",
  calendar: "M4 5h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zM16 3v4M8 3v4M3 10h18",
  copy: "M9 9h11v11H9zM5 15H4V4h11v1",
  "chev-r": "M9 6l6 6-6 6", "chev-l": "M15 6l-6 6 6 6", "chev-d": "M6 9l6 6 6-6", "chev-u": "M6 15l6-6 6 6",
  upload: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12",
  download: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3",
  check: "M20 6 9 17l-5-5", x: "M18 6 6 18M6 6l12 12",
  trash: "M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6",
  edit: "M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z",
  alert: "M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01",
  "alert-c": "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 8v4M12 16h.01",
  info: "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01",
  sparkles: "M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z",
  trend: "M3 17l6-6 4 4 8-8M14 7h7v7",
  clock: "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2",
  message: "M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z",
  file: "M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zM14 3v5h5",
  layers: "M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5",
  chart: "M3 3v18h18M7 16v-5M12 16V8M17 16v-3",
  logout: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9",
  moon: "M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z",
  menu: "M3 6h18M3 12h18M3 18h18",
  link: "M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7",
  eye: "M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z",
  archive: "M3 4h18v4H3zM5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4",
  printer: "M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z",
  send: "M22 2 11 13M22 2l-7 20-4-9-9-4z",
  coins: "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM15 9.5c-.5-1-1.6-1.5-3-1.5-1.7 0-3 .8-3 2s1.3 1.7 3 2 3 .8 3 2-1.3 2-3 2c-1.4 0-2.5-.5-3-1.5M12 6v2M12 16v2",
  wallet: "M3 7a2 2 0 0 1 2-2h13v4M3 7v10a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2zM16 14h.01",
  heart: "M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z",
  "check-circle": "M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3",
  zap: "M13 2 3 14h9l-1 8 10-12h-9z",
  store: "M3 9l1-5h16l1 5M3 9h18v11H3zM9 20v-6h6v6",
  mic: "M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3zM19 10a7 7 0 0 1-14 0M12 17v5",
  news: "M4 4h13a1 1 0 0 1 1 1v15H6a2 2 0 0 1-2-2zM18 8h2a1 1 0 0 1 1 1v9a2 2 0 0 1-2 2M8 8h6M8 12h6M8 16h4",
  billboard: "M3 4h18v10H3zM8 14v7M16 14v7",
  phone: "M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z",
  rocket: "M5 19c1-3 3-5 6-6M14 4c4 0 6 2 6 6-2 4-6 7-9 8l-5-5c1-3 4-7 8-9zM15 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2z",
  inbox: "M22 12h-6l-2 3h-4l-2-3H2M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z",
  grid: "M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z",
  "play-c": "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM10 8.5l5.5 3.5-5.5 3.5z",
  branch: "M6 3v12M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 9a9 9 0 0 1-9 9",
  merge: "M18 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 21V9a9 9 0 0 0 9 9",
  flag: "M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7",
  minus: "M5 12h14",
  maximize: "M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3",
};
document.body.insertAdjacentHTML("afterbegin", `<svg width="0" height="0" style="position:absolute" aria-hidden="true">${Object.entries(ICONS).map(([k, d]) => `<symbol id="i-${k}" viewBox="0 0 24 24"><path d="${d}"/></symbol>`).join("")}</svg>`);
const ic = (n, cls = "") => `<svg class="i ${cls}" aria-hidden="true"><use href="#i-${n}"/></svg>`;
const tile = (n, tint, cls = "") => `<span class="ti t-${tint} ${cls}">${ic(n)}</span>`;
/** Icône et couleur de chaque catégorie de canaux. */
const CAT_META = { social: ["share", "violet"], web: ["globe", "blue"], messaging: ["message", "teal"], ads: ["target", "orange"], media: ["news", "amber"], outdoor: ["billboard", "pink"], direct: ["phone", "green"], events: ["mic", "pink"], pr: ["users", "blue"], retail: ["store", "amber"], other: ["archive", "violet"] };
const catTile = (cat, cls = "sm") => { const m = CAT_META[cat] || CAT_META.other; return tile(m[0], m[1], cls); };
const catIc = (cat, cls = "sm") => { const m = CAT_META[cat] || CAT_META.other; return `<span style="color:var(--${m[1]})">${ic(m[0], cls)}</span>`; };
/** Icône et couleur des indicateurs du rapport. */
const KMETA = { leads: ["user-plus", "blue"], qualified: ["check-circle", "green"], qual_rate: ["check-circle", "green"], inquiries: ["message", "teal"], project_requests: ["folder", "pink"], raised: ["coins", "green"], visits: ["globe", "blue"], registrations: ["user-plus", "violet"], web_conv: ["trend", "violet"], ads_spend: ["wallet", "orange"], ads_leads: ["target", "orange"], cpl: ["coins", "orange"], posts: ["megaphone", "violet"], social_reach: ["share", "violet"], emails_sent: ["mail", "teal"], email_conversions: ["check-circle", "teal"] };
/** Les titres de cartes commencent souvent par un emoji : on le remplace par une tuile d'icône. */
const EMOJI_ICON = { "👥": ["users", "blue"], "💬": ["message", "teal"], "📁": ["folder", "pink"], "🌐": ["globe", "blue"], "📱": ["share", "violet"], "📢": ["target", "orange"], "✉️": ["mail", "teal"], "📊": ["funnel", "violet"], "📣": ["megaphone", "violet"], "🎯": ["target", "orange"], "💰": ["wallet", "orange"], "🗄": ["archive", "violet"], "🔔": ["bell", "amber"], "⏳": ["clock", "amber"], "💹": ["rocket", "green"], "✍️": ["edit", "violet"], "🔗": ["link", "blue"], "📤": ["send", "teal"], "👁": ["eye", "teal"], "📈": ["trend", "violet"], "🔥": ["zap", "pink"] };
function titleWithIcon(title) {
  for (const [e, [n, t]] of Object.entries(EMOJI_ICON)) if (title.startsWith(e + " ")) return tile(n, t, "sm") + title.slice(e.length + 1);
  return title;
}

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
const L = (n, k) => {
  if (k == null || k === "") return "";
  if (EN(n)[k] != null) return EN(n)[k];
  // Canal ou support retiré de la liste : on garde son nom sur les anciennes données.
  if (["sources", "platforms", "ad_channels"].includes(n) && EN("channel_labels")[k] != null) return EN("channel_labels")[k];
  if (n === "supports" && EN("support_labels")[k] != null) return EN("support_labels")[k];
  return k;
};
/* ---------- niveau 1 : canaux (par catégorie) · niveau 2 : supports (par type) */
const TX = () => S.boot.taxonomy;
const CHANNELS = () => S.boot.settings.channels;
const SUPPORTS = () => S.boot.settings.supports;
const catInfo = k => TX().categories.find(c => c.key === k) || { key: k || "other", label: "Canaux retirés" };
const channelOf = k => CHANNELS().find(c => c.key === k) || null;
const catOf = k => (channelOf(k) || {}).category || "";
const channelsIn = cat => CHANNELS().filter(c => c.category === cat);
const supportOf = k => SUPPORTS().find(x => x.key === k) || null;
const typeInfo = k => TX().support_types.find(t => t.key === k) || { key: k || "", group: "digital", label: "" };
const supportsOfChannel = ch => (channelOf(ch) || {}).supports || [];
/** Nom d'un canal (texte simple ; l'icône de catégorie s'affiche avec chCell). */
const chName = k => L("sources", k);
/** Clé stable dérivée d'un nom, comme côté serveur (mk_slug). */
const slugify = v => {
  const m = { à: "a", â: "a", ä: "a", é: "e", è: "e", ê: "e", ë: "e", î: "i", ï: "i", ô: "o", ö: "o", ù: "u", û: "u", ü: "u", ç: "c", ñ: "n" };
  return String(v).toLowerCase().trim().replace(/[àâäéèêëîïôöùûüçñ]/g, c => m[c]).replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 30) || "item";
};
const uniqueKey = (base, list) => { let k = base, i = 2; while (list.some(x => x.key === k)) k = base + "_" + i++; return k; };
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
  { group: "Pilotage" },
  { id: "overview", ic: "home", label: "Overview", tabs: [["daily", "Daily Monitoring"], ["weekly", "Weekly Reports"], ["monthly", "Monthly Reports"], ["archives", "Archives"]] },
  { id: "connexions", ic: "link", label: "Connexions", tabs: [["meta", "Facebook · Instagram · Ads"], ["rules", "Classement automatique"], ["history", "Historique"]] },
  { group: "Marketing" },
  { id: "comm", ic: "megaphone", label: "Communication", tabs: [["actions", "Actions"], ["performance", "Performance"], ["taxonomy", "Canaux & supports"]] },
  { id: "social", ic: "share", label: "Social Media", tabs: [["overview", "Vue d'ensemble"], ["stats", "Relevés des comptes"]] },
  { id: "ads", ic: "target", label: "Ads Manager", tabs: [["campaigns", "Campagnes"], ["budgets", "Budgets & objectifs"], ["results", "Résultats"]] },
  { id: "web", ic: "globe", label: "Website Analytics", tabs: [["website", "Site"], ["pages", "Landing pages"], ["registrations", "Inscriptions"]] },
  { id: "email", ic: "mail", label: "Email Marketing", tabs: [["campaigns", "Campagnes"], ["performance", "Performance"]] },
  { id: "funnels", ic: "funnel", label: "Funnel Analytics", tabs: [["list", "Funnels"], ["stages", "Parcours & suivi"], ["conversions", "Conversions"]] },
  { group: "Commercial" },
  { id: "leads", ic: "users", label: "Leads & Contacts", tabs: [["contacts", "Leads"], ["messages", "Messages"], ["comments", "Commentaires"], ["questions", "Questions"], ["profiles", "Profils"], ["sources", "Qualité par canal"], ["assignment", "Assignation"], ["treatment", "Traitement"]] },
  { id: "projects", ic: "folder", label: "Projets", tabs: [["requests", "Demandes"], ["funding", "En financement"]] },
  { id: "settings", ic: "settings", label: "Réglages", tabs: [], hidden: true },
];
const SECTIONS = NAV.filter(n => n.id);
const ALIAS = { "ads/campaign": "campaigns", "funnels/edit": "list" }; // onglet surligné pour une page sans onglet propre
/** Anciennes adresses (avant la suppression des doublons) → nouvelle place. */
const REDIRECT = {
  "social/leads": p => "leads/contacts/" + (p || "social"), "social/inquiries": p => "leads/questions" + (p && p !== "all" && p !== "social" ? "/" + p : ""),
  "social/activity": p => "comm/actions/" + (p && p !== "social" ? p : "social"), "social/performance": () => "social/overview",
  "web/leads": () => "leads/contacts/web", "email/sent": () => "email/campaigns", "email/engagement": () => "email/performance",
  "email/objectives": () => "email/performance", "ads/objectives": () => "ads/budgets", "funnels/stages-tags": () => "funnels/stages",
};

function parseHash() {
  const h = decodeURIComponent(location.hash.replace(/^#\/?/, "")) || "overview/daily";
  const [sec, tab, ...rest] = h.split("/");
  return { sec, tab: tab || "", param: rest.join("/") };
}
function renderNav(sec, tab) {
  $("#nav").innerHTML = NAV.filter(n => !n.hidden).map(n => n.group ? `<div class="nav-group">${esc(n.group)}</div>`
    : `<a class="nav-item${n.id === sec ? " on" : ""}" href="#${n.id}">${ic(n.ic)}<span>${esc(n.label)}</span>${n.id === "overview" && S.attention ? `<span class="badge">${S.attention}</span>` : ""}</a>`
      + (n.id === sec && n.tabs.length ? `<div class="nav-subs">${n.tabs.map(([k, l]) => `<a href="#${n.id}/${k}" class="${k === tab ? "on" : ""}">${esc(l)}</a>`).join("")}</div>` : "")).join("");
}
function renderTabs(def, tab) {
  $("#tabs").innerHTML = def.tabs.map(([k, l]) => `<a href="#${def.id}/${k}" class="${k === tab ? "on" : ""}">${esc(l)}</a>`).join("");
  $("#tabs").hidden = !def.tabs.length;
}
async function route() {
  if (!S.boot) return;
  if (S.tx && S.tx.dirty && !location.hash.startsWith("#comm/taxonomy")) {
    const leave = await askDialog({ icon: ["alert", "amber"], title: "Quitter sans enregistrer ?", text: "Vos changements dans Canaux & supports ne sont pas enregistrés. Si vous quittez cette page, ils seront perdus.", ok: "Quitter sans enregistrer", cancel: "Rester sur la page", danger: true });
    if (!leave) { location.hash = "#comm/taxonomy"; return; }
    S.tx = null;
  }
  if (S.fb && !location.hash.startsWith("#funnels/edit")) {
    if (S.fb.dirty) {
      const leave = await askDialog({ icon: ["alert", "amber"], title: "Quitter sans enregistrer ?", text: "Votre parcours n'est pas enregistré. Si vous quittez le Funnel Studio, les changements seront perdus.", ok: "Quitter sans enregistrer", cancel: "Rester dans le Studio", danger: true });
      if (!leave) { location.hash = "#funnels/edit/" + S.fb.param; return; }
    }
    S.fb = null;
    fbCloseMenu();
  }
  const { sec, tab, param } = parseHash();
  if (REDIRECT[sec + "/" + tab]) { location.replace("#" + REDIRECT[sec + "/" + tab](param)); return; }
  const def = SECTIONS.find(n => n.id === sec) || SECTIONS[0];
  const t = tab || (def.tabs[0] ? def.tabs[0][0] : "");
  const view = VIEWS[def.id + "/" + t] || VIEWS[def.id] || VIEWS["overview/daily"];
  const shown = ALIAS[def.id + "/" + t] || t;
  renderNav(def.id, shown);
  renderTabs(def, shown);
  $("#title").textContent = def.label;
  const tabLabel = (def.tabs.find(x => x[0] === shown) || [])[1];
  $("#crumb").textContent = tabLabel ? def.label + " · " + tabLabel : (S.boot.settings.org || "Marketing");
  if (tabLabel) $("#title").textContent = tabLabel;
  document.title = (tabLabel || def.label) + " — Marketing Dashboard";
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
    drawFlows();
  } catch (e) {
    if (tok === S.tok && e.status !== 401) V.innerHTML = empty("Cette page n'a pas pu s'afficher. " + e.message, `<button type="button" class="btn" onclick="location.reload()">${ic("trend", "sm")} Recharger</button>`, { icon: ["alert-c", "pink"] });
  }
}
window.addEventListener("hashchange", route);
window.addEventListener("beforeunload", e => { if ((S.tx && S.tx.dirty) || (S.fb && S.fb.dirty)) { e.preventDefault(); e.returnValue = ""; } });
$("#burger").addEventListener("click", () => $("#side").classList.toggle("open"));

/* ================================================================ briques d'affichage */
/** État vide : icône, titre (première phrase), explication, bouton pour commencer. */
const empty = (m, btn = "", opt = {}) => {
  const [t, rest] = String(m).split(/(?<=[.!?…])\s+(?=[A-ZÀ-Ý«])/u).length > 1 ? [String(m).split(/(?<=[.!?…])\s+(?=[A-ZÀ-Ý«])/u)[0], String(m).split(/(?<=[.!?…])\s+(?=[A-ZÀ-Ý«])/u).slice(1).join(" ")] : [String(m), ""];
  const [n, tint] = opt.icon || ["inbox", "violet"];
  return `<div class="empty">${tile(n, tint)}<b>${esc(t)}</b>${rest ? `<p>${esc(rest)}</p>` : ""}${btn ? `<div class="ctrl">${btn}</div>` : ""}</div>`;
};
const addBtn = (entity, label, preset, cls = "btn") => `<button type="button" class="${cls}" data-add="${entity}"${preset ? ` data-preset="${esc(JSON.stringify(preset))}"` : ""}>${ic("plus", "sm")} ${esc(label)}</button>`;
const card = (title, body, right = "", id = "") => `<section class="card"${id ? ` id="${id}"` : ""}><div class="card-h"><h2>${titleWithIcon(title)}</h2><div class="ctrl">${right}</div></div>${body}</section>`;
const actBtns = (entity, r) => `<button type="button" class="icon-btn" title="Modifier" aria-label="Modifier" data-edit="${entity}:${r.id}">${ic("edit", "sm")}</button><button type="button" class="icon-btn del" title="Supprimer" aria-label="Supprimer" data-del="${entity}:${r.id}">${ic("trash", "sm")}</button>`;

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
  if (!items.length) return empty("Aucune donnée sur la période. Les barres apparaissent dès que des chiffres sont saisis.");
  const max = Math.max(0, ...items.map(i => +i.value || 0)) || 1;
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
    + (p > 1 ? '<span class="pill crit">Dépassé</span>' : p >= 0.9 ? '<span class="pill warn">Presque épuisé</span>' : "");
}
function targetCell(achieved, target, elapsed, unit = "") {
  if (!(target > 0)) return '<span class="muted">pas d\'objectif chiffré</span>';
  const p = achieved / target;
  const verdict = p >= 1 ? '<span class="pill good">Atteint</span>'
    : elapsed != null && elapsed >= 0.5 && p < elapsed * 0.7 ? '<span class="pill warn">En retard</span>'
    : elapsed != null && p >= elapsed ? '<span class="pill blue">En avance</span>' : "";
  return `<div class="mcell">${meter(p, p >= 1 ? "good" : "", elapsed, `${num(achieved)} / ${num(target)} ${esc(unit)}`)}<span>${pct(p)}</span></div><div class="sub">${num(achieved)} / ${num(target)} ${esc(unit)} ${verdict}</div>`;
}

/* ================================================================ rapport */
const KHREF = {
  leads: "#leads/contacts", qualified: "#leads/treatment", qual_rate: "#leads/treatment", inquiries: "#leads/questions",
  project_requests: "#projects/requests", raised: "#projects/funding", visits: "#web/pages", registrations: "#web/registrations",
  web_conv: "#web/pages", ads_spend: "#ads/budgets", ads_leads: "#ads/results", cpl: "#ads/results", posts: "#comm/actions/social",
  social_reach: "#social/overview", emails_sent: "#email/campaigns", email_conversions: "#email/performance",
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
function kpiTile(k, extraCls = "", meta = null) {
  const [d, cls] = kdelta(k);
  const vs = d && d !== "nouveau" ? `<span>vs ${kval(k, k.prev)}</span>` : "";
  const m = meta || KMETA[k.key];
  const inner = `${m ? tile(m[0], m[1]) : ""}<small>${esc(k.label)}</small><b>${kval(k, k.value)}</b><span class="d ${cls}">${d ? `<span class="dv">${esc(d.replace("▲ ", "↑ ").replace("▼ ", "↓ "))}</span>` : "&nbsp;"}${vs}</span>`;
  const href = KHREF[k.key];
  return href ? `<a class="kpi ${extraCls}" href="${href}">${inner}</a>` : `<div class="kpi ${extraCls}">${inner}</div>`;
}
const LV = { critical: ["alert-c", "Urgent"], warning: ["alert", "À surveiller"], good: ["trend", "Bonne nouvelle"], info: ["sparkles", "À savoir"] };
function insightsCard(list, title = "Analyse & décisions") {
  const body = list.length ? `<ul class="ins">${list.map(i => `<li class="${i.level}"><span class="lv">${ic(LV[i.level][0])}</span><span class="it"><small>${LV[i.level][1]}</small>${esc(i.text)}</span></li>`).join("")}</ul>`
    : empty("Rien à signaler. Aucun point d'alerte sur la période : tout suit son cours.", "", { icon: ["check-circle", "green"] });
  return `<section class="card"><div class="card-h"><h2>${tile("sparkles", "violet", "sm")}${esc(title)}</h2></div>${body}</section>`;
}
function backlogTiles(b) {
  const t = (label, v, href, cls = "") => `<a class="kpi ${v > 0 ? cls : ""}" href="${href}"><small>${esc(label)}</small><b>${num(v)}</b><span class="d">&nbsp;</span></a>`;
  return t("Leads à traiter", b.untreated, "#leads/treatment", "warn") + t("Sans contact depuis 48\u00a0h", b.stale, "#leads/treatment", "alert")
    + t("Leads non assignés", b.unassigned, "#leads/assignment", "warn") + t("Questions en attente", b.open_inquiries, "#leads/questions", "warn")
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
    { l: "Commercial", h: r => r.c ? esc(r.c) : '<span class="pill warn">Non assigné</span>' },
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
  const rows = Object.entries(SOC.platforms || {}).map(([p, v]) => ({ p, ...v })).filter(r => r.posts || r.reach || r.impressions || r.followers);
  const t = SOC.totals;
  return table(rows, [
    { l: "Réseau social", h: r => `<b>${esc(L("platforms", r.p))}</b>` },
    { l: "Actions", n: 1, h: r => num(r.posts) },
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
    { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a><div class="sub">${esc(L("ad_channels", c.platform))}</div>` },
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

/* ---------- KPIs des étapes : catalogue (calculés) et KPIs manuels (saisis) */
const parseKpis = v => { try { const a = typeof v === "string" ? JSON.parse(v || "[]") : v; return Array.isArray(a) ? a : []; } catch (e) { return []; } };
const kpiLabel = kd => String(kd.k).startsWith("m_") ? kd.l : L("stage_kpis", kd.k);
const kpiFmtOf = kd => String(kd.k).startsWith("m_") ? kd.f : EN("stage_kpi_fmt")[kd.k] || "num";
const kpiVal = (v, fmt) => v == null ? "—" : fmt === "pct" ? pct(v, 1) : fmt === "pctv" ? num(v, 1) + " %" : fmt === "money" ? money(Math.round(v)) : num(v, v % 1 ? 1 : 0);
const kpiTarget = k => k.target == null ? "" : k.fmt === "pct" || k.fmt === "pctv" ? num(k.target, 1) + " %" : k.fmt === "money" ? money(k.target) : num(k.target);
/** KPIs proposés d'office selon la source des chiffres d'une étape. */
const KPI_DEFAULTS = { stage_posts: ["reach", "engagement_rate"], stage_reach: ["reach", "engagement_rate", "clicks"], stage_engagement: ["reach", "engagement", "engagement_rate"],
  stage_clicks: ["clicks", "ctr"], stage_conversions: ["conversions", "clicks"], stage_leads: ["leads", "qual_rate"], stage_leads_qualified: ["leads", "qual_rate"], stage_inquiries: ["inquiries"],
  stage_conversations: ["conversations", "conversations_hot"], stage_conversations_hot: ["conversations_hot", "prospects_messages"], stage_prospects_messages: ["prospects_messages"],
  ads_impressions: ["ads_spend", "ads_ctr"], ads_clicks: ["ads_spend", "ads_ctr"], ads_leads: ["ads_spend", "ads_cpl"], ads_conversions: ["ads_spend", "ads_conversions"],
  social_reach: ["social_reach", "new_followers"], social_engagement: ["social_engagement", "followers"], social_clicks: ["social_reach"],
  page_visits: ["page_visits", "page_conv_rate"], page_registrations: ["page_registrations", "page_conv_rate"], page_forms: ["page_forms"],
  email_sent: ["email_sent", "open_rate"], email_opens: ["open_rate", "click_rate"], email_clicks: ["click_rate"], email_conversions: ["click_rate"],
  contacts_qualified: ["qual_rate"], contacts_converted: ["conv_rate"] };
const kpiDefaults = src => (KPI_DEFAULTS[src] || []).filter(k => EN("stage_kpis")[k]).map(k => ({ k, t: 0 }));
const supChips = keys => (keys || []).map(k => `<span class="chan sup${supportOf(k) ? "" : " gone"}">${ic("layers", "xs")}${esc(L("supports", k))}</span>`).join("");

/* ---------- parcours : un funnel est un réseau d'étapes (plusieurs entrées, aiguillages, circuits qui se rejoignent) */
const KIND_META = { entry: ["play-c", "green", "Point d'entrée"], step: ["layers", "violet", "Étape"], split: ["branch", "blue", "Aiguillage"], goal: ["flag", "orange", "Objectif final"] };
/** Sources de chiffres qui comptent des leads : on peut les limiter à un segment (investisseurs, qualifiés…). */
const LEAD_SRC = src => /^(contacts|stage_leads|stage_inquiries)/.test(src || "");
const FL = new Map(); // connecteurs de chaque parcours affiché (dessinés une fois les étapes en place)
let flSeq = 0;

/**
 * Colonnes du dessin : une étape se place juste après la plus lointaine des étapes qui y mènent ;
 * dans une colonne, chaque étape se range près de ses étapes précédentes (moins de croisements).
 */
function flowLayout(nodes) {
  const by = new Map(nodes.map(n => [n.key, n]));
  const lvl = new Map();
  const level = (k, depth = 0) => {
    if (lvl.has(k)) return lvl.get(k);
    const ps = by.get(k).parents.filter(p => by.has(p));
    const v = ps.length && depth < 60 ? 1 + Math.max(...ps.map(p => level(p, depth + 1))) : 0;
    lvl.set(k, v);
    return v;
  };
  nodes.forEach(n => level(n.key));
  const cols = [];
  nodes.forEach(n => (cols[lvl.get(n.key)] = cols[lvl.get(n.key)] || []).push(n));
  const pos = new Map();
  const bary = n => { const ps = n.parents.filter(p => pos.has(p)); return ps.length ? ps.reduce((s, p) => s + pos.get(p), 0) / ps.length : .5; };
  return cols.filter(Boolean).map((col, l) => {
    if (l) col.sort((a, b) => bary(a) - bary(b) || nodes.indexOf(a) - nodes.indexOf(b));
    col.forEach((n, i) => pos.set(n.key, col.length > 1 ? i / (col.length - 1) : .5));
    return col;
  });
}

/** Étapes d'un funnel calculé (api « funnel », rapports) au format du dessin. Un ancien rapport archivé est en ligne droite. */
const flowNodes = f => {
  const legacy = !f.stages.some(s => Array.isArray(s.parents));
  return f.stages.map((s, i) => Object.assign({ kind: i ? "step" : "entry", branch: "", segment: "", channels: [], tags: [], from_name: "" }, s, {
    key: String(s.id != null ? s.id : i),
    parents: legacy ? (i ? [String(f.stages[i - 1].id != null ? f.stages[i - 1].id : i - 1)] : []) : s.parents.map(String),
  }));
};

function flowNode(n, o) {
  const km = KIND_META[n.kind] || KIND_META.step;
  const cls = "fn k-" + (KIND_META[n.kind] ? n.kind : "step") + (o.sel === n.key ? " sel" : "") + (o.worst === n.key ? " worst" : "") + (o.best && o.best.has(n.key) ? " best" : "")
    + (o.linkOk && o.linkOk.has(n.key) ? " target" : "") + (o.linkFrom === n.key ? " origin" : "");
  const seg = n.segment ? `<span class="fn-seg">${esc(L("stage_segments", n.segment))}</span>` : "";
  const head = `<div class="fn-top">${tile(km[0], km[1], "xs")}<span class="fn-kind">${esc(km[2])}</span>${seg}</div><b class="fn-name">${esc(n.name || "Sans nom")}</b>`;
  if (o.mini) return `<div class="${cls}" data-k="${esc(n.key)}">${head}</div>`;
  const ch = n.channels || [], sp = n.supports || [];
  const chips = ch.length || sp.length ? `<div class="fn-ch">${chanChips(ch.slice(0, 3))}${ch.length > 3 ? `<span class="chan">+${ch.length - 3}</span>` : ""}${supChips(sp.slice(0, 2))}${sp.length > 2 ? `<span class="chan sup">+${sp.length - 2}</span>` : ""}</div>` : "";
  if (o.data) {
    const conv = n.parents.length
      ? `<div class="fn-conv">${o.worst === n.key ? ic("alert", "xs") + " " : ""}<b>${pct(n.conv_prev, 1)}</b> de « ${esc(n.from_name || "l'étape précédente")} »</div>`
      : `<div class="fn-conv">Entrée du parcours</div>`;
    const tipTxt = `<b>${esc(n.name)}</b>${esc(km[2])} · ${esc(L("stage_sources", n.source))}<br>Prospects : ${num(n.value)}`
      + (n.parents.length ? `<br>Depuis « ${esc(n.from_name)} » : ${pct(n.conv_prev, 1)}${n.lost ? " · " + num(n.lost) + " non passés" : ""}` : "")
      + (n.conv_start != null ? "<br>Depuis l'entrée : " + pct(n.conv_start, 1) : "")
      + (n.target ? "<br>Objectif : " + num(n.target) + " (" + pct(n.target_pct) + ")" : "")
      + (ch.length ? "<br>Canaux : " + ch.map(k => esc(L("sources", k))).join(", ") : "")
      + (sp.length ? "<br>Supports : " + sp.map(k => esc(L("supports", k))).join(", ") : "")
      + (n.kpis || []).map(k => "<br>" + esc(k.label) + " : <b>" + kpiVal(k.value, k.fmt) + "</b>" + (k.target ? " / " + kpiTarget(k) : "")).join("")
      + (n.segment ? "<br>Segment : " + esc(L("stage_segments", n.segment)) : "")
      + (o.worst === n.key ? "<br><b>Plus forte perte du parcours</b>" : "");
    const kps = (n.kpis || []).slice(0, o.compact ? 2 : 6);
    const kpiBox = kps.length ? `<div class="fn-kpis">${kps.map(k => `<span class="fn-kpi${k.target_pct == null ? "" : k.target_pct >= 1 ? " ok" : " ko"}"><small>${esc(k.label)}</small><b>${kpiVal(k.value, k.fmt)}</b></span>`).join("")}${n.kpis.length > kps.length ? `<span class="fn-kpi more"><small>+ ${n.kpis.length - kps.length} autre(s) KPI au survol</small></span>` : ""}</div>` : "";
    return `<div class="${cls}" data-k="${esc(n.key)}" data-tip="${esc(tipTxt)}">${head}<div class="fn-val"><b>${num(n.value)}</b>${n.target ? `<span>/ ${num(n.target)}</span>` : ""}</div>${conv}${n.target ? meter(n.target_pct, n.target_pct >= 1 ? "good" : "") : ""}${kpiBox}${o.compact ? "" : chips}</div>`;
  }
  // Constructeur : la source des chiffres, les canaux, et le « + » pour ajouter ou relier la suite.
  const warn = needsCh(n.source) && !ch.length ? `<div class="fn-warn">${ic("alert", "xs")} Aucun canal choisi</div>` : "";
  const kd = n.kpis || [];
  const kpiTags = kd.length ? `<div class="fn-ktags">${kd.slice(0, 4).map(k => `<span class="ktag">${ic("chart", "xs")}${esc(kpiLabel(k))}</span>`).join("")}${kd.length > 4 ? `<span class="ktag">+${kd.length - 4}</span>` : ""}</div>` : "";
  return `<div class="${cls}" data-k="${esc(n.key)}" tabindex="0" role="button" aria-label="${esc(km[2] + " : " + (n.name || "sans nom"))}">${head}<div class="fn-src">${esc(L("stage_sources", n.source))}</div>${chips}${kpiTags}${warn}`
    + `<button type="button" class="fn-port" data-fa="${esc(n.key)}" title="Ajouter la suite (ou glisser jusqu'à une étape pour la relier)" aria-label="Ajouter après « ${esc(n.name || "cette étape")} »">${ic("plus", "xs")}</button></div>`;
}

/**
 * Dessin d'un parcours : colonnes d'étapes reliées par des courbes. En suivi (o.data), l'épaisseur
 * d'un lien suit le nombre de prospects qui passent, et chaque branche d'un aiguillage affiche sa part.
 */
function flowHtml(nodes, o = {}) {
  if (!nodes.length) return empty("Ce funnel n'a pas encore d'étapes.");
  const id = "fl" + ++flSeq;
  const by = new Map(nodes.map(n => [n.key, n]));
  const kids = new Map();
  nodes.forEach(n => n.parents.forEach(p => kids.set(p, (kids.get(p) || 0) + 1)));
  const top = o.data ? Math.max(1, ...nodes.map(n => +n.value || 0)) : 1;
  const edges = [];
  for (const n of nodes) for (const p of n.parents) {
    const a = by.get(p);
    if (!a) continue;
    const e = { from: p, to: n.key, label: o.mini ? "" : n.branch || "" };
    if (o.data) {
      const sum = n.parents.reduce((s, q) => s + (+(by.get(q) || {}).value || 0), 0);
      const flow = sum > 0 ? (+n.value || 0) * (+a.value || 0) / sum : 0;
      e.w = 2 + 12 * Math.min(1, flow / top);
      if (n.parents.length === 1 && +a.value > 0 && kids.get(p) > 1 && n.value <= a.value) e.pct = n.value / a.value;
      e.tip = `<b>${esc(a.name)} → ${esc(n.name)}</b>${n.branch ? "Branche « " + esc(n.branch) + " »<br>" : ""}${n.parents.length > 1 ? "≈ " : ""}${num(flow)} prospect(s)`;
    }
    if (o.bestEdges && o.bestEdges.has(p + ">" + n.key)) e.best = true;
    edges.push(e);
  }
  FL.set(id, { edges, zoom: o.zoom || 1, edit: !!o.edit, fit: !!o.mini, auto: !!o.data });
  if (FL.size > 200) FL.delete(FL.keys().next().value);
  return `<div class="fl${o.edit ? " edit" : ""}${o.data ? " data" : ""}${edges.some(e => e.label || e.pct != null) ? " lb" : ""}${o.compact ? " compact" : ""}${o.mini ? " mini" : ""}${o.linkFrom ? " linking" : ""}" data-fl="${id}"><div class="fl-scroll"><div class="fl-sizer"><div class="fl-stage"><svg class="fl-svg"></svg>`
    + flowLayout(nodes).map(c => `<div class="fl-col">${c.map(n => flowNode(n, o)).join("")}</div>`).join("") + `</div></div></div></div>`;
}

/** Trace les liens d'un parcours affiché (après la mise en page : positions réelles des étapes). */
function drawFlow(fl) {
  const data = FL.get(fl.dataset.fl);
  const stage = $(".fl-stage", fl);
  if (!data || !stage || !fl.isConnected) return;
  // Écran étroit : le parcours se lit de haut en bas.
  const vert = !fl.classList.contains("mini") && fl.clientWidth < (fl.classList.contains("compact") ? 560 : 700);
  fl.classList.toggle("v", vert);
  const W = stage.offsetWidth, H = stage.offsetHeight;
  // Aperçu (modèles) : tout tient dans la carte. Suivi chiffré : réduit au besoin (pas en dessous de 70 %).
  const avail = $(".fl-scroll", fl).clientWidth - 8;
  const z = data.fit ? Math.min(1, Math.max(.3, avail / W)) : data.auto ? Math.min(1, Math.max(.7, avail / W)) : data.zoom || 1;
  stage.style.transform = z !== 1 ? `scale(${z})` : "";
  const sizer = $(".fl-sizer", fl);
  sizer.style.width = z !== 1 ? Math.ceil(W * z) + "px" : "";
  sizer.style.height = z !== 1 ? Math.ceil(H * z) + "px" : "";
  const box = new Map($$(".fn", stage).map(n => [n.dataset.k, [n.offsetLeft, n.offsetTop, n.offsetWidth, n.offsetHeight]]));
  const svg = $(".fl-svg", stage);
  svg.setAttribute("width", W);
  svg.setAttribute("height", H);
  svg.setAttribute("viewBox", `0 0 ${W} ${H}`);
  $$(".fl-el", stage).forEach(x => x.remove());
  const mk = fl.dataset.fl + "a";
  let g = `<defs><marker id="${mk}" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="8" markerHeight="8" markerUnits="userSpaceOnUse" orient="auto"><path d="M0 0L10 5L0 10z" class="fl-arrow"/></marker></defs>`;
  const labels = [];
  for (const e of data.edges) {
    const a = box.get(e.from), b = box.get(e.to);
    if (!a || !b) continue;
    let p;
    if (vert) {
      const x1 = a[0] + a[2] / 2, y1 = a[1] + a[3], x2 = b[0] + b[2] / 2, y2 = b[1] - 1, d = Math.max(26, (y2 - y1) / 2);
      p = [x1, y1, x1, y1 + d, x2, y2 - d, x2, y2];
    } else {
      const x1 = a[0] + a[2], y1 = a[1] + a[3] / 2, x2 = b[0] - 1, y2 = b[1] + b[3] / 2, d = Math.max(34, (x2 - x1) / 2);
      p = [x1, y1, x1 + d, y1, x2 - d, y2, x2, y2];
    }
    const d = `M${p.map(v => v.toFixed(1)).slice(0, 2).join(",")} C${p.slice(2).map(v => v.toFixed(1)).join(" ")}`;
    const ek = esc(e.from + ">" + e.to);
    g += `<path class="fl-e${e.best ? " best" : ""}" d="${d}" data-e="${ek}"${e.w ? ` style="stroke-width:${e.w.toFixed(1)}px"` : ` marker-end="url(#${mk})"`}/>`;
    if (e.best && e.w) g += `<path class="fl-run" d="${d}"/>`;
    if (e.tip) g += `<path class="fl-hit" d="${d}" data-tip="${esc(e.tip)}"/>`;
    if (e.label || e.pct != null) {
      const mx = (p[0] + 3 * p[2] + 3 * p[4] + p[6]) / 8, my = (p[1] + 3 * p[3] + 3 * p[5] + p[7]) / 8;
      const txt = (e.label ? `<b>${esc(e.label)}</b>` : "") + (e.pct != null ? `<span>${pct(e.pct)}</span>` : "");
      labels.push(data.edit
        ? `<button type="button" class="fl-el" style="left:${mx.toFixed(0)}px;top:${my.toFixed(0)}px" data-fe="${esc(e.to)}" title="Renommer la branche">${txt}</button>`
        : `<span class="fl-el${e.best ? " best" : ""}" style="left:${mx.toFixed(0)}px;top:${my.toFixed(0)}px">${txt}</span>`);
    }
  }
  svg.innerHTML = g;
  if (labels.length) stage.insertAdjacentHTML("beforeend", labels.join(""));
  // Parcours plus large que l'écran (téléphone) : on montre d'abord son milieu.
  const sc = $(".fl-scroll", fl);
  if (!data.centered && sc.scrollWidth > sc.clientWidth + 4) { data.centered = true; if (vert) sc.scrollLeft = (sc.scrollWidth - sc.clientWidth) / 2; }
}
/** Redessine tous les parcours de la page ; suit les changements de taille (écran, rotation, polices). */
const flRO = window.ResizeObserver ? new ResizeObserver(list => requestAnimationFrame(() => list.forEach(x => drawFlow(x.target)))) : null;
function drawFlows() {
  if (flRO) flRO.disconnect();
  $$(".fl[data-fl]").forEach(fl => { drawFlow(fl); if (flRO) flRO.observe(fl); });
}
if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => drawFlows());
// Survol d'une étape : ses liens entrants et sortants ressortent.
let flHover = null;
document.addEventListener("mouseover", e => {
  const n = e.target.closest && e.target.closest(".fl .fn");
  if (n === flHover) return;
  if (flHover) $$(".fl-e.hl", flHover.closest(".fl") || document).forEach(p => p.classList.remove("hl"));
  flHover = n;
  if (n) $$(".fl-e", n.closest(".fl")).forEach(p => { const [a, b] = p.dataset.e.split(">"); if (a === n.dataset.k || b === n.dataset.k) p.classList.add("hl"); });
});

/** Options de dessin d'un funnel calculé : plus forte perte, et meilleur circuit quand il y en a plusieurs. */
function flowOpts(f, extra = {}) {
  const o = Object.assign({ data: true }, extra);
  if (f.worst != null && f.stages[f.worst] && f.stages[f.worst].drop > 0) o.worst = String(f.stages[f.worst].id);
  if (f.paths && f.paths.length > 1 && f.best_path != null && f.paths[f.best_path]) {
    const ids = f.paths[f.best_path].ids.map(String);
    o.best = new Set(ids);
    o.bestEdges = new Set(ids.slice(1).map((x, i) => ids[i] + ">" + x));
  }
  return o;
}
const FLOW_LEGEND = `<div class="fl-legend">${["entry", "step", "split", "goal"].map(k => `<span><i class="k-${k}"></i>${esc(KIND_META[k][2])}</span>`).join("")}<span class="muted">L'épaisseur d'un lien suit le nombre de prospects qui passent · survolez une étape pour le détail.</span></div>`;

function funnelTable(f) {
  return table(f.stages, [
    { l: "Étape", h: s => `<b>${esc(s.name)}</b><div class="small muted">${esc((KIND_META[s.kind] || KIND_META.step)[2])}${s.segment ? " · " + esc(L("stage_segments", s.segment)) : ""}</div>${s.tags.length ? "<div>" + s.tags.map(t => `<span class="tag">#${esc(t)}</span>`).join("") + "</div>" : ""}` },
    { l: "Vient de", h: s => (s.parents || []).length ? esc(s.from_name) + (s.branch ? ` <span class="tag">${esc(s.branch)}</span>` : "") : '<span class="muted">Entrée</span>' },
    { l: "Canaux & supports", wrap: 1, h: s => s.channels.length || (s.supports || []).length ? chanChips(s.channels) + supChips(s.supports) : '<span class="muted">—</span>' },
    { l: "KPIs", wrap: 1, h: s => (s.kpis || []).length ? s.kpis.map(k => `<div class="small nowrap"><span class="muted">${esc(k.label)} :</span> <b>${kpiVal(k.value, k.fmt)}</b>${k.target ? ` <span class="muted">/ ${kpiTarget(k)}</span>` : ""}</div>`).join("") : '<span class="muted">—</span>' },
    { l: "Source des chiffres", h: s => `<span class="small">${esc(L("stage_sources", s.source))}</span>` + (needsCh(s.source) && !s.channels.length ? '<div><span class="pill warn">Aucun canal</span></div>' : "") },
    { l: "Prospects", n: 1, h: s => num(s.value) },
    { l: "Conversion", n: 1, h: s => pct(s.conv_prev, 1) },
    { l: "Depuis l'entrée", n: 1, h: s => pct(s.conv_start, 1) },
    { l: "Non passés", n: 1, h: s => s.drop == null ? "—" : (s === f.stages[f.worst] ? `<span class="pill warn">${pct(s.drop, 1)}</span>` : pct(s.drop, 1)) },
    { l: "Objectif", h: s => s.target ? `<div class="mcell">${meter(s.target_pct, s.target_pct >= 1 ? "good" : "")}<span>${pct(s.target_pct)}</span></div>` : '<span class="muted">—</span>' },
  ]);
}
/** Chaque circuit complet (d'une entrée à une fin), du plus performant au moins performant. */
function funnelPaths(f) {
  if (!f.paths || !f.paths.length) return "";
  const byId = new Map(f.stages.map(s => [String(s.id), s]));
  const top = Math.max(1e-9, ...f.paths.map(p => p.conv || 0));
  const rows = f.paths.map((p, i) => Object.assign({ i, est: p.ids.some(id => ((byId.get(String(id)) || {}).parents || []).length > 1) }, p));
  rows.sort((a, b) => (b.conv == null ? -1 : b.conv) - (a.conv == null ? -1 : a.conv));
  return table(rows, [
    { l: "Circuit", wrap: 1, h: p => `<div class="path">${p.names.map(n => `<span>${esc(n)}</span>`).join(ic("chev-r", "xs"))}</div>${p.i === f.best_path && f.paths.length > 1 ? '<span class="pill good">Meilleur circuit</span>' : ""}` },
    { l: "Au départ", n: 1, h: p => num(p.start) },
    { l: "Arrivées", n: 1, h: p => p.flow == null ? "—" : (p.est ? "≈ " : "") + num(p.flow) },
    { l: "Conversion du circuit", h: p => p.conv == null ? '<span class="muted">—</span>' : `<div class="mcell">${meter(p.conv / top, p.i === f.best_path ? "good" : "")}<span>${pct(p.conv, 1)}</span></div>` },
  ]) + (rows.some(p => p.est) ? '<p class="small muted" style="margin:8px 0 0">≈ Quand des circuits se rejoignent, les arrivées sont estimées au prorata de ce que chaque circuit apporte.</p>' : "");
}
/** Chiffres clés d'un parcours : entrées, arrivées, conversion globale, plus forte perte. */
function funnelKpis(f) {
  const roots = f.stages.filter(s => !(s.parents || []).length);
  const goals = f.stages.filter(s => s.kind === "goal");
  const ends = goals.length ? goals : f.stages.filter(s => !(s.children || []).length);
  const sum = l => l.reduce((a, s) => a + (+s.value || 0), 0);
  const names = l => esc(l.map(s => s.name).join(" · "));
  const w = f.worst != null ? f.stages[f.worst] : null;
  const best = f.paths && f.paths.length > 1 && f.best_path != null ? f.paths[f.best_path] : null;
  const kt = (l, v, m, sub) => `<div class="kpi">${tile(m[0], m[1])}<small>${esc(l)}</small><b>${v}</b><span class="d">${sub || "&nbsp;"}</span></div>`;
  return `<div class="kpis">${kt(roots.length > 1 ? `Entrées (${roots.length} points)` : "Entrées", num(sum(roots)), ["play-c", "green"], names(roots))}`
    + kt("Arrivées à l'objectif", num(sum(ends)), ["flag", "orange"], names(ends))
    + kt("Conversion globale", pct(f.overall, 1), ["trend", "violet"], best ? "meilleur circuit : " + pct(best.conv, 1) : num((f.paths || []).length) + " circuit")
    + kt("Plus forte perte", w && w.drop > 0 ? pct(w.drop) : "—", ["alert", "amber"], w && w.drop > 0 ? esc(w.from_name) + " → " + esc(w.name) : "aucune sur la période") + `</div>`;
}
function funnelCard(f, extra = "") {
  const links = [];
  if (f.links.campaigns) links.push(f.links.campaigns + " campagne(s)");
  if (f.links.pages) links.push(f.links.pages + " page(s)");
  if (f.links.emails) links.push(f.links.emails + " email(s)");
  if ((f.links.channels || []).length) links.push(f.links.channels.length + " canal(aux)");
  const n = (f.paths || []).length;
  const head = `<p class="sub" style="margin:-4px 0 12px">${f.objective ? esc(f.objective) + " · " : ""}${links.length ? "Associé à : " + esc(links.join(" · ")) : "Aucune association"}${n > 1 ? ` · ${num(n)} circuits` : ""}${f.overall != null ? ` · <b>conversion globale ${pct(f.overall, 1)}</b>` : ""}</p>`;
  return card(`📊 ${esc(f.name)}`, head + flowHtml(flowNodes(f), flowOpts(f, { compact: true })) + extra, `<a class="btn sm" href="#funnels/stages/${f.id}">Ouvrir</a>`);
}

/** En-tête d'un rapport : période, navigation, actions. */
function periodHead(tab, type, r, date) {
  const p = r.period;
  const unit = { day: "jour", week: "semaine", month: "mois" }[type];
  const cmp = p.partial ? `${unit === "jour" ? "" : "période en cours, "}comparée au même nombre de jours (${fdate(p.prev_from, false)} → ${fdate(p.prev_to)})` : `comparé à ${p.prev_from === p.prev_to ? fdate(p.prev_from) : fdate(p.prev_from, false) + " → " + fdate(p.prev_to)}`;
  return `<div class="hello"><div><h1>Rapport ${esc(r.type_label)}</h1><p>${esc(p.label)} · ${esc(cmp)}. Calculé automatiquement à partir de toutes les sections.</p></div>
    <div class="rep-ctrl">${dateNav(tab, type, date, type === "week" ? "Semaine contenant le" : "Mois contenant le")}${reportActions(type, date)}</div></div>`;
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
    <section class="card"><div class="card-h"><h2>${tile("bell", "pink", "sm")}À traiter maintenant</h2></div><div class="kpis" style="margin:0">${backlogTiles(r.backlog)}</div></section>
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
    ${card("📣 Communication · canaux & supports", commSummary(r.comm), `<a class="btn sm" href="#comm/performance">Performance détaillée</a>`)}
    ${card("📱 Social Media", socialTable(r.social), `<a class="btn sm" href="#social/overview">Détails</a>`)}
    ${card("📢 Ads Manager", `<h3 style="margin-top:0">Résultats de la période</h3>${adsTable(r.ads)}<h3>Budgets & objectifs (cumul depuis le début de chaque campagne)</h3>${budgetsTable(r.ads.budgets)}`, `<a class="btn sm" href="#ads/campaigns">Campagnes</a>`)}
    ${card("✉️ Email Marketing", emailTable(r.email.list) + (r.email.list.length ? ESTIMATE : ""), `<a class="btn sm" href="#email/campaigns">Campagnes</a>`)}
    ${r.funnels.length ? `<h2 class="sec-h">${tile("funnel", "violet", "sm")}Funnel Analytics</h2>` + r.funnels.map(f => funnelCard(f)).join("") : ""}`;
}

/* ================================================================ vues : Overview */
const VIEWS = {};
const longDate = d => new Date(d + "T12:00:00").toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
const DOW = ["L", "M", "M", "J", "V", "S", "D"];
/** Mini-calendrier du mois : un point sous chaque jour où quelque chose a été saisi. */
function calHtml(month, days, sel) {
  const first = month + "-01";
  const start = monday(first);
  const label = new Date(first + "T12:00:00").toLocaleDateString("fr-FR", { month: "long", year: "numeric" });
  let cells = "";
  for (let i = 0; i < 42; i++) {
    const d = addDays(start, i);
    if (i >= 35 && d.slice(0, 7) !== month) break;
    const a = days[d], n = a ? Object.values(a).reduce((x, y) => x + y, 0) : 0;
    const tip = a ? [a.leads && a.leads + " lead(s)", a.questions && a.questions + " question(s)", a.actions && a.actions + " action(s)", a.projects && a.projects + " projet(s)"].filter(Boolean).join(" · ") : "";
    const cls = [d.slice(0, 7) !== month && "out", d === S.today && "today", d === sel && "sel", d > S.today && "future"].filter(Boolean).join(" ");
    cells += `<button type="button" class="${cls}" data-day="${d}" aria-label="${esc(longDate(d) + (tip ? " — " + tip : ""))}"${tip ? ` data-tip="${esc("<b>" + fdate(d) + "</b>" + tip)}"` : ""}${d > S.today ? " disabled" : ""}>${+d.slice(8)}${n ? '<i class="dot"></i>' : ""}</button>`;
  }
  return `<div class="cal-h"><b>${ic("calendar", "sm")} ${esc(label.charAt(0).toUpperCase() + label.slice(1))}</b><span><button type="button" class="icon-btn" data-calm="-1" aria-label="Mois précédent">${ic("chev-l", "sm")}</button><button type="button" class="icon-btn" data-calm="1" aria-label="Mois suivant"${month >= S.today.slice(0, 7) ? " disabled" : ""}>${ic("chev-r", "sm")}</button></span></div>
    <div class="cal">${DOW.map(x => `<span class="dow">${x}</span>`).join("")}${cells}</div>`;
}
const RECENT_META = { contacts: ["user-plus", "blue"], inquiries: ["message", "teal"], posts: ["megaphone", "violet"], projects: ["folder", "pink"], campaigns: ["target", "orange"], emails: ["mail", "green"] };
function ago(ts) {
  const s = Math.max(0, Date.now() / 1000 - ts), rt = new Intl.RelativeTimeFormat("fr", { numeric: "auto" });
  if (s < 60) return "à l'instant";
  if (s < 3600) return rt.format(-Math.round(s / 60), "minute");
  if (s < 86400) return rt.format(-Math.round(s / 3600), "hour");
  if (s < 86400 * 30) return rt.format(-Math.round(s / 86400), "day");
  return new Date(ts * 1000).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}
function recentHtml(items) {
  if (!items.length) return empty("Rien de saisi pour l'instant. Chaque lead, question ou action enregistré apparaîtra ici.", "", { icon: ["clock", "violet"] });
  return `<ul class="tl">${items.map(x => {
    const m = RECENT_META[x.kind] || ["file", "violet"];
    const open = x.kind === "campaigns" ? `href="#ads/campaign/${x.id}"` : `href="javascript:void 0" data-edit="${x.kind}:${x.id}"`;
    return `<li style="--dot:var(--${m[1]})"><a ${open}><b>${esc(x.text)}</b></a><span class="sub">${esc(x.sub || "")}${x.sub ? " · " : ""}${esc(ago(x.created))}</span></li>`;
  }).join("")}</ul>`;
}
/** Boutons d'un rapport (envoyer, archiver, CSV, imprimer). */
function reportActions(type, date) {
  const b = (act, icon, label, txt = "") => `<button type="button" class="btn${txt ? "" : " icon"}" data-report="${act}:${type}:${date}" title="${esc(label)}" aria-label="${esc(label)}">${ic(icon, "sm")}${txt ? " " + esc(txt) : ""}</button>`;
  return `<div class="rep-act">${b("send", "send", "Envoyer le rapport par email", "Envoyer")}${b("archive", "archive", "Archiver le rapport")}${b("csv", "download", "Télécharger le rapport (CSV)")}<button type="button" class="btn icon" data-report="print" title="Imprimer ou enregistrer en PDF" aria-label="Imprimer ou enregistrer en PDF">${ic("printer", "sm")}</button></div>`;
}
/** Navigation de date : précédent, sélecteur, suivant, retour à aujourd'hui. */
function dateNav(tab, type, date, label) {
  return `<div class="datebox"><button type="button" class="icon-btn" data-shift="${tab}:${type}:${date}:-1" aria-label="Période précédente">${ic("chev-l")}</button>
    <label class="d"><small>${esc(label)}</small><input type="date" value="${date}" max="${S.today}" data-pick="${tab}" aria-label="Choisir la date"></label>
    <button type="button" class="icon-btn" data-shift="${tab}:${type}:${date}:1" aria-label="Période suivante"${shiftDate(type, date, 1) > S.today ? " disabled" : ""}>${ic("chev-r")}</button></div>`
    + (date !== S.today ? `<a class="btn ghost sm" href="#overview/${tab}">Revenir à aujourd'hui</a>` : "");
}

/** Carte « Meta » de l'accueil : invitation à relier les comptes, ou état de la synchronisation. */
function metaHomeCard() {
  const mt = S.boot.meta || {};
  if (!mt.connected) return `<section class="card promo"><div class="card-h"><h2>${tile("link", "violet", "sm")}Remplissage automatique</h2></div><p class="small" style="margin-top:0">Reliez Facebook, Instagram et Meta Ads : relevés, publications, campagnes, leads des formulaires et questions en commentaire arrivent tout seuls, déjà classés.</p><a class="btn pri sm" href="#connexions/meta">${ic("link", "sm")} Relier mes comptes</a></section>`;
  const st = mt.token_invalid ? '<span class="pill crit">Connexion expirée</span>' : mt.last_result && !mt.last_result.ok ? '<span class="pill warn">Dernière synchro en erreur</span>' : mt.last_sync ? '<span class="pill good">À jour</span>' : '<span class="pill">Pas encore synchronisé</span>';
  return `<section class="card"><div class="card-h"><h2>${tile("link", "violet", "sm")}Meta</h2><a class="btn sm ghost" href="#connexions/meta">Gérer</a></div><p class="small" style="margin:0">${st} Dernière synchronisation : <b>${esc(metaWhen(mt.last_sync))}</b>${+mt.auto_sync ? `<br>Automatique : toutes les ${+mt.auto_sync >= 60 ? num(mt.auto_sync / 60) + " h" : num(mt.auto_sync) + " min"}` : "<br>Synchronisation automatique désactivée"}${[mt.page && "Page " + mt.page.name, mt.instagram && "@" + mt.instagram.username, mt.ad_accounts && mt.ad_accounts.length && mt.ad_accounts.length + " compte(s) pub"].filter(Boolean).map(x => "<br>" + esc(x)).join("")}</p></section>`;
}
VIEWS["overview/daily"] = { fn: async (param, tok) => {
  const date = isDate(param) ? param : S.today;
  const [r, rec, cal] = await Promise.all([api("report", { type: "day", date }).then(d => d.report), api("recent").catch(() => ({ items: [] })), api("activity_days", { month: date.slice(0, 7) }).catch(() => ({ month: date.slice(0, 7), days: {} }))]);
  S.lastReport = r;
  S.calMonth = cal.month;
  const k = Object.fromEntries(r.kpis.map(x => [x.key, x]));
  const d = r.day, b = r.backlog;
  remember("contacts", [...d.contacts, ...b.leads_list]);
  remember("inquiries", [...d.inquiries, ...b.inquiries_list]);
  remember("posts", d.posts);
  remember("projects", d.projects);
  const leadCols = [
    { l: "Lead", h: c => `<div class="who"><b>${esc(c.name)}</b><div class="sub">${esc(fdate(c.date))}${c.phone ? " · " + esc(c.phone) : ""}</div></div>` },
    { l: "Profil", h: c => esc(L("contact_types", c.type)) },
    { l: "Canal", h: c => chCell(c.source) + (c.campaign_id ? `<div class="sub">${esc(refName("campaigns", c.campaign_id))}</div>` : "") },
    { l: "Commercial", h: c => inlineSel("contacts", c, "assigned_to") },
    { l: "Traitement", h: c => inlineSel("contacts", c, "status") },
  ];
  const inqCols = [
    { l: "Question", wrap: 1, h: q => `<b>${esc(q.name || "Anonyme")}</b> <span class="muted small">${esc(fdate(q.date))} · ${esc(L("inq_categories", q.category))}</span><div class="sub">${esc(q.question)}</div>` },
    { l: "Canal", h: q => chCell(q.source) },
    { l: "Statut", h: q => inlineSel("inquiries", q, "status") },
  ];
  const isToday = date === S.today;
  const hour = new Date().getHours();
  const who = (S.boot.settings.owner_name || "").trim();
  const hello = isToday ? `${hour < 18 ? "Bonjour" : "Bonsoir"}${who ? ", " + esc(who) : ""} 👋` : esc(longDate(date).replace(/^./, c => c.toUpperCase()));
  const intro = isToday ? `Voici l'essentiel d'aujourd'hui, ${esc(longDate(date))}, comparé à hier.` : `Journée passée, comparée à la veille. Les chiffres se recalculent dès qu'une donnée change.`;
  // Onglets du jour (divulgation progressive : un seul tableau visible à la fois).
  const dayTabs = [
    ["contacts", "Leads", "user-plus", d.contacts, table(d.contacts, leadCols, { entity: "contacts", emptyMsg: "Aucun lead ce jour. Ajoutez-le dès qu'un prospect vous contacte, quel que soit le canal." }), addBtn("contacts", "Ajouter un lead", { date }, "btn sm pri")],
    ["inquiries", "Questions", "message", d.inquiries, table(d.inquiries, inqCols, { entity: "inquiries", emptyMsg: "Aucune question ce jour. Notez ici les questions reçues par message, téléphone ou commentaire." }), addBtn("inquiries", "Ajouter une question", { date }, "btn sm pri")],
    ["posts", "Actions", "megaphone", d.posts, table(d.posts, [{ l: "Canal", h: p => chCell(p.platform, p.format) }, { l: "Contenu", wrap: 1, h: p => esc(p.topic) + (p.link ? ` <a href="${esc(p.link)}" target="_blank" rel="noopener" aria-label="Ouvrir la publication">${ic("link", "xs")}</a>` : "") }, { l: "Portée", n: 1, h: p => num(p.reach) }, { l: "Leads", n: 1, h: p => num(p.leads) }], { entity: "posts", emptyMsg: "Aucune action de communication ce jour. Enregistrez vos publications, emails ou événements pour suivre leur effet." }), addBtn("posts", "Enregistrer une action", { date }, "btn sm pri")],
    ["projects", "Projets", "folder", d.projects, table(d.projects, [{ l: "Projet", h: p => `<b>${esc(p.name)}</b><div class="sub">${esc(p.owner || "")}</div>` }, { l: "Secteur", h: p => esc(p.sector || "—") }, { l: "Montant recherché", n: 1, h: p => money(p.amount_target) }, { l: "Statut", h: p => inlineSel("projects", p, "status") }], { entity: "projects", emptyMsg: "Aucune demande de projet ce jour." }), addBtn("projects", "Ajouter une demande", { date }, "btn sm pri")],
  ];
  const firstTab = (dayTabs.find(t => t[3].length) || dayTabs[0])[0];
  const att = [
    ["stale", "Sans contact depuis 48\u00a0h", "Leads à rappeler en priorité", "alert-c", "pink", b.stale, "#leads/treatment/nouveau"],
    ["untreated", "Leads à traiter", "Nouveaux ou contactés, pas encore qualifiés", "users", "orange", b.untreated, "#leads/treatment/nouveau"],
    ["unassigned", "Leads sans commercial", "À confier à un commercial", "user-plus", "blue", b.unassigned, "#leads/assignment"],
    ["messages", "Messages sans réponse", `Messenger et Instagram · ${num(+S.boot.messages_replied || 0)} déjà répondue(s)`, "message", "blue", +S.boot.messages_waiting || 0, "#leads/messages/attente"],
    ["comments", "Commentaires sans réponse", `Facebook et Instagram · ${num(+S.boot.comments_replied || 0)} déjà répondu(s)`, "message", "violet", +S.boot.comments_waiting || 0, "#leads/comments/attente"],
    ["open_inquiries", "Questions sans réponse", "Nouvelles ou en cours", "message", "teal", b.open_inquiries, "#leads/questions"],
    ["pending_projects", "Projets à étudier", "Demandes reçues ou en étude", "folder", "violet", b.pending_projects, "#projects/requests"],
  ];
  const quick = [["contacts", "Ajouter un lead", "user-plus", "blue"], ["inquiries", "Noter une question", "message", "teal"], ["posts", "Enregistrer une action", "megaphone", "violet"], ["ad_results", "Saisir des résultats pub", "target", "orange"], ["social_stats", "Relever un compte social", "share", "pink"], ["projects", "Ajouter une demande de projet", "folder", "green"]];
  const insMax = 5;
  const ins = r.insights;
  const insBody = ins.length ? `<ul class="ins">${ins.slice(0, insMax).map(i => `<li class="${i.level}"><span class="lv">${ic(LV[i.level][0])}</span><span class="it"><small>${LV[i.level][1]}</small>${esc(i.text)}</span></li>`).join("")}</ul>`
    + (ins.length > insMax ? `<details class="more"><summary>Voir les ${ins.length - insMax} autres points</summary><ul class="ins">${ins.slice(insMax).map(i => `<li class="${i.level}"><span class="lv">${ic(LV[i.level][0])}</span><span class="it"><small>${LV[i.level][1]}</small>${esc(i.text)}</span></li>`).join("")}</ul></details>` : "")
    : empty("Rien à signaler. Aucun point d'alerte aujourd'hui : tout suit son cours.", "", { icon: ["check-circle", "green"] });
  const mt = S.boot.meta || {};
  const metaWarn = mt.connected && mt.token_invalid ? `<a class="banner warn" href="#connexions/meta">${ic("alert", "sm")} La connexion à Meta a expiré : les chiffres de Facebook, Instagram et Meta Ads ne se mettent plus à jour. Reconnectez-vous.</a>`
    : mt.connected && mt.token_kind !== "manual" && mt.days_left != null && mt.days_left <= 7 ? `<a class="banner warn" href="#connexions/meta">${ic("clock", "sm")} La connexion à Meta expire dans ${num(Math.max(0, mt.days_left))} jour(s) : cliquez ici pour la renouveler.</a>`
    : mt.fb_connected && !mt.page ? `<a class="banner warn" href="#connexions/meta">${ic("alert", "sm")} Aucune Page Facebook choisie : Facebook, les leads et les messages ne sont pas importés. Cliquez ici pour choisir la Page.</a>` : "";
  return metaWarn + `<div class="hello"><div><h1>${hello}</h1><p>${intro}</p></div><div class="rep-ctrl">${dateNav("daily", "day", date, isToday ? "Aujourd'hui" : "Jour affiché")}${reportActions("day", date)}</div></div>
    <div class="kpis k5">${["leads", "qualified", "inquiries", "posts", "project_requests"].map(x => kpiTile(k[x])).join("")}</div>
    <div class="dash"><div class="main-col">
      <section class="card"><div class="card-h"><h2>${tile("sparkles", "violet", "sm")}Analyse du jour</h2><span class="muted small">${ins.length ? num(ins.length) + " point(s), du plus urgent au plus informatif" : ""}</span></div>${insBody}</section>
      <section class="card"><div class="card-h"><h2>${tile("calendar", "blue", "sm")}${isToday ? "Saisi aujourd'hui" : "Saisi ce jour-là"}</h2><div class="seg" role="tablist" aria-label="Données du jour">${dayTabs.map(t => `<button type="button" role="tab" data-dtab="${t[0]}" aria-selected="${t[0] === firstTab}">${ic(t[2], "sm")} ${t[1]} <em>${num(t[3].length)}</em></button>`).join("")}</div></div>
        ${dayTabs.map(t => `<div class="dpanel" data-dpanel="${t[0]}"${t[0] === firstTab ? "" : " hidden"}><div class="toolbar" style="justify-content:flex-end">${t[5]}</div>${t[4]}</div>`).join("")}</section>
      ${card(`👥 Leads en attente <span class="muted small">(${num(b.leads_list.length)}${b.leads_list.length >= 60 ? "+" : ""})</span>`, table(b.leads_list, leadCols, { entity: "contacts", max: 8, more: '<a href="#leads/treatment/nouveau">Voir tous les leads à traiter</a>', emptyMsg: "Aucun lead en attente. Tous les leads ont été traités et assignés : bravo !" }), `<a class="btn sm" href="#leads/treatment/nouveau">Tout voir</a>`)}
    </div><div class="side-col">
      <section class="card"><div class="card-h"><h2>${tile("bell", "pink", "sm")}À traiter maintenant</h2></div><div class="alist">${att.map(a => `<a class="arow${a[5] ? "" : " zero"}" href="${a[6]}">${tile(a[3], a[4], "sm")}<span><b>${esc(a[1])}</b><small>${esc(a[2])}</small></span><span class="n">${num(a[5])}</span>${ic("chev-r", "sm chev")}</a>`).join("")}</div></section>
      <section class="card"><div class="card-h"><h2>${tile("zap", "amber", "sm")}Saisie rapide</h2><button type="button" class="btn sm ghost" data-import="contacts">${ic("upload", "sm")} Importer</button></div><div class="qa">${quick.map(q => `<button type="button" data-add="${q[0]}" data-preset="${esc(JSON.stringify({ date }))}">${tile(q[2], q[3], "sm")}${esc(q[1])}${ic("plus", "sm chev")}</button>`).join("")}</div></section>
      ${metaHomeCard()}
      <section class="card" id="calCard">${calHtml(cal.month, cal.days, date)}</section>
      <section class="card"><div class="card-h"><h2>${tile("clock", "teal", "sm")}Activité récente</h2></div>${recentHtml(rec.items)}</section>
    </div></div>`;
}, after: () => {
  const card0 = V.querySelector(".seg");
  if (card0) card0.addEventListener("click", e => {
    const bt = e.target.closest("[data-dtab]");
    if (!bt) return;
    $$("[data-dtab]", card0).forEach(x => x.setAttribute("aria-selected", x === bt ? "true" : "false"));
    $$("[data-dpanel]", V).forEach(p => { p.hidden = p.dataset.dpanel !== bt.dataset.dtab; });
  });
  const cc = $("#calCard");
  if (cc) cc.addEventListener("click", async e => {
    const day = e.target.closest("[data-day]"), m = e.target.closest("[data-calm]");
    if (day) { location.hash = "#overview/daily/" + day.dataset.day; return; }
    if (m && !m.disabled) {
      const month = addMonths(S.calMonth + "-01", +m.dataset.calm).slice(0, 7);
      try { const c = await api("activity_days", { month }); S.calMonth = c.month; cc.innerHTML = calHtml(c.month, c.days, parseHash().param || S.today); } catch (err) { toast(err.message, "err"); }
    }
  });
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
    return `<div class="toolbar"><a class="btn ghost" href="#overview/archives">${ic("chev-l", "sm")} Toutes les archives</a></div>
      <div class="hello"><div><h1>Rapport ${esc(r.type_label)}</h1><p>${esc(r.period.label)} · comparé à ${fdate(r.period.prev_from, false)} → ${fdate(r.period.prev_to)}</p></div><div class="rep-ctrl"><button type="button" class="btn" data-report="print">${ic("printer", "sm")} Imprimer / PDF</button></div></div>
      <div class="banner">${ic("archive", "sm")} Copie figée le ${fdate(new Date(d.meta.created * 1000).toISOString().slice(0, 10))}${d.meta.sent_to ? ", envoyée à " + esc(d.meta.sent_to) : ""} : les chiffres sont ceux du moment de l'archivage.</div>` + reportBody(r);
  }
  const rows = (await api("archives")).rows;
  const T = { day: "Quotidien", week: "Hebdomadaire", month: "Mensuel", custom: "Période" };
  return `<p class="muted" style="margin-top:0">Chaque matin, l'envoi automatique archive (et envoie par email) le rapport de la veille ; le lundi, celui de la semaine écoulée ; le 1er, celui du mois écoulé. Un rapport archivé garde les chiffres du moment où il a été produit.</p>`
    + card("🗄 Rapports archivés", table(rows, [
      { l: "Rapport", h: a => `<a href="#overview/archives/${a.id}"><b>${esc(a.label)}</b></a>` },
      { l: "Type", h: a => `<span class="pill blue">${T[a.type] || a.type}</span>` },
      { l: "Produit le", h: a => fdate(new Date(a.created * 1000).toISOString().slice(0, 10)) + " · " + (a.origin === "cron" ? "automatique" : a.origin.endsWith("-echec") ? '<span class="pill warn">Envoi échoué</span>' : "manuel") },
      { l: "Envoyé à", wrap: 1, h: a => esc(a.sent_to || "—") },
      { l: "", h: a => `<a class="btn sm" href="#overview/archives/${a.id}">Ouvrir</a> <button type="button" class="icon-btn del" data-archive-del="${a.id}" title="Supprimer ce rapport archivé" aria-label="Supprimer ce rapport archivé">${ic("trash", "sm")}</button>` },
    ], { emptyMsg: "Aucun rapport archivé pour l'instant. Le premier arrivera demain matin si l'envoi automatique est installé (Réglages), ou archivez un rapport vous-même depuis son en-tête." }));
} };

/* ================================================================ vues : Funnels */
function funnelPicker(selected, base) {
  const fs = REF("funnels");
  return `<select data-goto="${base}" aria-label="Funnel">${fs.map(f => `<option value="${f.id}"${+f.id === +selected ? " selected" : ""}>${esc(f.name)}${f.status !== "actif" ? " (" + esc(L("funnel_status", f.status)) + ")" : ""}</option>`).join("")}</select>`;
}
const NEW_FUNNEL = `<a class="btn pri" href="#funnels/edit/new">${ic("plus", "sm")} Créer un funnel</a>`;

VIEWS["funnels/list"] = { range: true, fn: async () => {
  const fs = REF("funnels");
  const intro = `<div class="fb-intro">${tile("branch", "violet")}<div><b>Des parcours, pas une ligne droite.</b><p>Un funnel peut avoir plusieurs points d'entrée (publicité, Instagram, messages, salon…), se séparer en branches (qualifié / sinon, investisseurs / porteurs) et se rejoindre plus loin. Chaque étape se remplit toute seule (campagnes, pages, emails, canaux, messages, leads) ou par saisie. Période : ${esc(rangeLabel())}.</p></div></div>`;
  if (!fs.length) return intro + empty("Aucun funnel pour l'instant. Créez votre premier parcours à partir d'un modèle : simple, à plusieurs entrées ou à branches.", NEW_FUNNEL, { icon: ["branch", "violet"] });
  const [from, to] = curRange();
  const data = await Promise.all(fs.map(f => cached("funnel", { id: f.id, from, to })));
  return intro + `<div class="toolbar">${NEW_FUNNEL}</div>` + data.map(d => funnelCard(d.data,
    `<div class="toolbar" style="margin:12px 0 0"><span class="pill ${d.funnel.status === "actif" ? "good" : ""}">${esc(L("funnel_status", d.funnel.status))}</span><span class="grow"></span><a class="btn sm" href="#funnels/edit/${d.funnel.id}">${ic("edit", "sm")} Modifier le parcours</a><button type="button" class="btn sm del" data-del="funnels:${d.funnel.id}">${ic("trash", "sm")} Supprimer</button></div>`)).join("");
} };

VIEWS["funnels/stages"] = { range: true, fn: async param => {
  const fs = REF("funnels");
  if (!fs.length) return empty("Aucun funnel pour l'instant.", NEW_FUNNEL, { icon: ["branch", "violet"] });
  const id = +param || +fs[0].id;
  const [from, to] = curRange();
  const d = await cached("funnel", { id, from, to });
  const f = d.funnel, D = d.data;
  remember("funnel_entries", d.entries);
  const manual = f.stages.filter(s => s.source === "manual");
  const L_links = [
    ...REF("campaigns").filter(c => f.links.campaigns.includes(+c.id)).map(c => `<a class="tag" href="#ads/campaign/${c.id}">${ic("target", "xs")} ${esc(c.name)}</a>`),
    ...REF("pages").filter(p => f.links.pages.includes(+p.id)).map(p => `<span class="tag">${ic("globe", "xs")} ${esc(p.name)}</span>`),
    ...REF("emails").filter(e => f.links.emails.includes(+e.id)).map(e => `<span class="tag">${ic("mail", "xs")} ${esc(e.name)}</span>`),
  ];
  return `<div class="toolbar">${funnelPicker(id, "funnels/stages")}<span class="grow"></span><a class="btn" href="#funnels/edit/${f.id}">${ic("edit", "sm")} Modifier le parcours</a>${NEW_FUNNEL}</div>`
    + funnelKpis(D)
    + card(`📊 ${esc(f.name)}`, `<p class="sub" style="margin:-4px 0 10px">${f.objective ? esc(f.objective) + " · " : ""}${esc(L("audiences", f.audience) || "Toutes cibles")} · ${esc(L("funnel_status", f.status))} · ${esc(rangeLabel())}${f.description ? "<br>" + esc(f.description) : ""}</p>`
      + flowHtml(flowNodes(D), flowOpts(D)) + FLOW_LEGEND, `<a class="btn sm" href="#funnels/edit/${f.id}">${ic("edit", "sm")} Modifier</a>`)
    + (D.paths.length > 1 ? card("🎯 Circuits du parcours", `<p class="sub" style="margin:-4px 0 10px">Chaque chemin complet, d'un point d'entrée jusqu'à une fin : comparez-les pour savoir où mettre vos efforts.</p>` + funnelPaths(D)) : "")
    + card(`Détail des étapes · ${esc(rangeLabel())}`, `<p class="sub" style="margin:-4px 0 10px">Associé à : ${L_links.join(" ") || "aucune campagne, page ou email. Les canaux de communication se choisissent étape par étape, dans « Modifier le parcours »."}</p>` + funnelTable(D))
    + (() => {
      S.fTrack = d;
      const mk = manualInputs(f);
      const kpiName = e => { if (!e.kpi) return "Prospects"; const st = f.stages.find(x => +x.id === +e.stage_id); const k = st && parseKpis(st.kpis).find(x => x.k === e.kpi); return k ? k.l : e.kpi; };
      const kpiUnit = e => { if (!e.kpi) return num(e.count); const st = f.stages.find(x => +x.id === +e.stage_id); const k = st && parseKpis(st.kpis).find(x => x.k === e.kpi); return kpiVal(+e.count, k ? k.f : "num"); };
      const names = [...new Set(mk.map(x => "« " + x.stage.name + " »" + (x.kpi ? " · " + x.label : "")))];
      return card("✍️ Chiffres saisis à la main", (mk.length ? `<p class="sub" style="margin:-4px 0 10px">À saisir : ${names.map(esc).join(", ")}. Le reste se remplit tout seul.</p>` : '<p class="sub">Tout se remplit automatiquement. Pour suivre un chiffre que le tableau de bord ne connaît pas (rendez-vous, appels…), ajoutez un KPI manuel à une étape dans « Modifier le parcours ».</p>')
        + table(d.entries, [{ l: "Date", h: e => fdate(e.date) }, { l: "Étape", h: e => esc(refName("funnel_stages", e.stage_id)) }, { l: "Chiffre", h: e => esc(kpiName(e)) }, { l: "Valeur", n: 1, h: kpiUnit }, { l: "Note", h: e => esc(e.notes || "") }], { entity: "funnel_entries", emptyMsg: "Aucun chiffre saisi." }),
        mk.length ? `<button type="button" class="btn sm pri" id="feOpen">${ic("edit", "sm")} Saisir des chiffres</button>` : "");
    })();
}, after: () => {
  const b = $("#feOpen");
  if (b && S.fTrack) b.addEventListener("click", () => openFunnelEntry(S.fTrack.funnel, S.fTrack.entries));
} };

/** Ce qui se saisit à la main dans un funnel : prospects des étapes « saisie manuelle » et KPIs manuels. */
function manualInputs(f) {
  const out = [];
  for (const st of f.stages) {
    if (st.source === "manual") out.push({ stage: st, kpi: "", label: "Prospects", fmt: "num", agg: "sum" });
    for (const k of parseKpis(st.kpis)) if (String(k.k).startsWith("m_")) out.push({ stage: st, kpi: k.k, label: k.l, fmt: k.f, agg: k.a });
  }
  return out;
}
/** Fenêtre de saisie : une date, puis un champ par chiffre manuel, regroupés par étape. */
function openFunnelEntry(f, entries = []) {
  const ins = manualInputs(f);
  if (!ins.length) return toast("Rien à saisir dans ce funnel : ajoutez un KPI manuel ou une étape en saisie manuelle dans « Modifier le parcours ».", "err");
  const groups = [];
  ins.forEach((x, i) => { x.i = i; let g = groups.find(y => y.stage === x.stage); if (!g) groups.push(g = { stage: x.stage, ins: [] }); g.ins.push(x); });
  const unit = x => x.fmt === "pctv" ? "%" : x.fmt === "money" ? cur() : "";
  openModal(`<div class="imp">${tile("edit", "violet")}<h2>Saisir des chiffres</h2>
    <p class="muted" style="margin-top:-4px">« ${esc(f.name)} » : laissez vide ce que vous ne connaissez pas. Un chiffre déjà saisi pour cette date est remplacé.</p>
    <form id="feForm" novalidate><div class="form"><div class="fld"><label for="feD">Date</label><input type="date" id="feD" value="${S.today}" max="${S.today}"></div><div class="fld"><label for="feN">Note (facultatif)</label><input type="text" id="feN" maxlength="200" placeholder="ex. salon Djazagro"></div></div>
    ${groups.map(g => `<h3>${esc(g.stage.name)}</h3><div class="form">${g.ins.map(x => `<div class="fld"><label for="fe${x.i}">${esc(x.label)}${x.agg === "last" ? ' <span class="muted">(valeur à cette date)</span>' : ""}</label><div class="in-unit"><input type="text" inputmode="decimal" id="fe${x.i}" placeholder="—" autocomplete="off">${unit(x) ? `<em>${esc(unit(x))}</em>` : ""}</div></div>`).join("")}</div>`).join("")}
    </form><p class="form-err" id="feErr"></p>
    <div class="form-foot"><button type="button" class="btn" data-close>Annuler</button><button type="button" class="btn pri" id="feGo">${ic("check", "sm")} Enregistrer</button></div></div>`);
  // Date déjà saisie : ses chiffres s'affichent pour être corrigés.
  const fill = () => {
    const dt = $("#feD").value;
    ins.forEach(x => { const e = entries.find(y => y.date === dt && +y.stage_id === +x.stage.id && (y.kpi || "") === x.kpi); $("#fe" + x.i).value = e ? String(+e.count).replace(".", ",") : ""; });
  };
  fill();
  $("#feD").addEventListener("change", fill);
  $("#fe0").focus();
  $("#feGo").addEventListener("click", async e => {
    e.target.disabled = true;
    try {
      const r = await api("funnel_entries_save", {}, { funnel_id: +f.id, date: $("#feD").value, notes: $("#feN").value, values: ins.map(x => ({ stage_id: +x.stage.id, kpi: x.kpi, value: $("#fe" + x.i).value })) });
      closeModal();
      toast(`${num(r.saved)} chiffre(s) enregistré(s)`);
      S.cache.clear();
      route();
    } catch (err) { $("#feErr").textContent = err.message; e.target.disabled = false; }
  });
}

VIEWS["funnels/conversions"] = { range: true, fn: async param => {
  const fs = REF("funnels");
  if (!fs.length) return empty("Aucun funnel pour l'instant.", NEW_FUNNEL, { icon: ["branch", "violet"] });
  const [from, to] = curRange();
  const list = param ? fs.filter(f => +f.id === +param) : fs;
  const data = await Promise.all(list.map(f => cached("funnel", { id: f.id, from, to })));
  const chips = `<div class="chips"><a class="chip${param ? "" : " on"}" href="#funnels/conversions">Tous</a>${fs.map(f => `<a class="chip${+param === +f.id ? " on" : ""}" href="#funnels/conversions/${f.id}">${esc(f.name)}</a>`).join("")}</div>`;
  return chips + `<p class="muted" style="margin-top:0">Prospects par étape, conversion depuis l'étape précédente et depuis l'entrée, circuits les plus efficaces et étape où l'on perd le plus — ${esc(rangeLabel())}.</p>`
    + data.map(d => card(`📊 ${esc(d.data.name)}${d.data.overall != null ? ` <span class="pill blue">conversion globale ${pct(d.data.overall, 1)}</span>` : ""}`,
      (d.data.paths.length > 1 ? `<h3 style="margin-top:0">Circuits</h3>${funnelPaths(d.data)}<h3>Étapes</h3>` : "") + funnelTable(d.data),
      `<a class="btn sm" href="#funnels/stages/${d.data.id}">Voir le parcours</a>`)).join("");
} };

/* ================================================================ vues : leads, questions, réseaux sociaux */
const socialKeys = () => Object.keys(EN("platforms"));
/** Canal d'une ligne : petite icône de catégorie + nom (+ support s'il est connu). */
const chCell = (k, sup = "") => k ? `<span class="chan-cat">${catIc(catOf(k) || "other")}<b>${esc(L("sources", k))}</b></span>${sup ? `<div class="sub">${esc(L("supports", sup))}</div>` : ""}` : '<span class="muted">—</span>';
function contactCols() {
  return [
    { l: "Reçu le", h: c => fdate(c.date) },
    { l: "Lead", h: c => `<div class="who"><b>${esc(c.name)}</b>${metaTag(c)}<div class="sub">${esc([c.phone, c.email, c.city].filter(Boolean).join(" · "))}</div></div>` },
    { l: "Profil", h: c => esc(L("contact_types", c.type)) },
    { l: "Canal", h: c => chCell(c.source, c.support) + (c.campaign_id || c.page_id || c.email_id ? `<div class="sub">${esc([refName("campaigns", c.campaign_id), refName("pages", c.page_id), refName("emails", c.email_id)].filter(Boolean).join(" · "))}</div>` : "") },
    { l: "Commercial", h: c => inlineSel("contacts", c, "assigned_to") },
    { l: "Traitement", h: c => inlineSel("contacts", c, "status") + (c.status === "non_qualifie" && c.reason ? `<div class="sub">${esc(L("reasons", c.reason))}${c.reason_note ? " — " + esc(c.reason_note) : ""}</div>` : "") },
    { l: "Notes", wrap: 1, h: c => c.notes ? `<span class="small">${esc(c.notes.slice(0, 120))}</span>` : "" },
  ];
}
function inquiryCols() {
  return [
    { l: "Reçue le", h: q => fdate(q.date) },
    { l: "Canal", h: q => chCell(q.source) },
    { l: "De", h: q => `<b>${esc(q.name || (q.link ? "Nom masqué par Meta" : "Anonyme"))}</b>${metaTag(q)}<div class="sub">${q.link ? `<a href="${esc(q.link)}" target="_blank" rel="noopener">${ic("link", "xs")} ${q.source === "instagram" ? "Voir la publication" : "Voir le commentaire"}</a>` : esc(q.contact || "")}${q.type ? " · " + esc(L("contact_types", q.type)) : ""}</div>` },
    { l: "Question", wrap: 1, h: q => `<span class="pill">${esc(L("inq_categories", q.category))}</span><div class="sub">${esc(q.question)}</div>${q.answer ? `<div class="sub">↳ ${esc(q.answer.slice(0, 160))}</div>` : ""}` },
    { l: "Pris en charge", h: q => inlineSel("inquiries", q, "assigned_to") },
    { l: "Statut", h: q => inlineSel("inquiries", q, "status") },
  ];
}
/** Pastilles de catégories de canaux (avec le nombre de lignes de chacune). */
function catChips(base, sel, rows, key) {
  const counts = {};
  for (const r of rows) { const c = catOf(r[key]) || "other"; counts[c] = (counts[c] || 0) + 1; }
  return `<div class="chips"><a class="chip${!sel || sel === "all" ? " on" : ""}" href="#${base}">Tous les canaux (${num(rows.length)})</a>${TX().categories.filter(c => counts[c.key]).map(c => `<a class="chip${sel === c.key ? " on" : ""}" href="#${base}/${c.key}">${catIc(c.key)} ${esc(c.label)} (${num(counts[c.key])})</a>`).join("")}${counts.other ? `<a class="chip${sel === "other" ? " on" : ""}" href="#${base}/other">Canaux retirés (${num(counts.other)})</a>` : ""}</div>`;
}
const inCat = (sel, key) => r => !sel || sel === "all" ? true : (catOf(r[key]) || "other") === sel;

/**
 * Synchronisation Meta par lots : chaque appel dure 40 secondes au plus (les hébergeurs coupent les requêtes
 * longues) ; tant qu'il reste quelque chose, le lot suivant reprend là où le précédent s'est arrêté.
 */
async function metaSyncLots(body = {}, onLot = null) {
  const tot = { added: 0, updated: 0 };
  let d, lot = 0, clean = true, req = Object.assign({}, body);
  do {
    lot++;
    if (onLot) onLot(lot);
    d = await api("meta_sync", {}, req);
    S.boot.meta = d.meta;
    tot.added += +d.result.totals.added || 0;
    tot.updated += +d.result.totals.updated || 0;
    clean = clean && d.result.ok;
    req = Object.assign({}, body, { only: d.result.pending || [], resume: true, clean });
  } while (d.result.more && lot < 80);
  return { d, tot, ok: clean, lots: lot, more: !!d.result.more };
}

/* ---------- messages et commentaires : période choisie ou tout l'historique, import complet, « afficher plus » */
const histAll = () => { try { return localStorage.getItem("mk_hist") === "all"; } catch (e) { return false; } };
const histToggle = () => {
  const all = histAll();
  return `<div class="seg" role="group" aria-label="Période affichée"><button type="button" data-hist="range" aria-selected="${!all}">${ic("calendar", "sm")} ${esc(rangeLabel())}</button><button type="button" data-hist="all" aria-selected="${all}">${ic("archive", "sm")} Tout l'historique</button></div>`;
};
const fullBtn = what => `<button type="button" class="btn" data-fullsync="${what}">${ic("download", "sm")} Importer tout l'historique</button>`;
const MORE = 100;
const moreBtn = (shown, total) => total > shown
  ? `<div class="more-row"><button type="button" class="btn" data-more>${ic("chev-d", "sm")} Afficher ${num(Math.min(MORE, total - shown))} de plus <span class="muted">· ${num(total - shown)} restant${total - shown > 1 ? "s" : ""}</span></button></div>`
  : total > MORE ? `<p class="small muted more-row">Tout est affiché : ${num(total)}.</p>` : "";
document.addEventListener("click", async e => {
  const h = e.target.closest("[data-hist]");
  if (h) { try { localStorage.setItem("mk_hist", h.dataset.hist); } catch (err) {} return route(); }
  const b = e.target.closest("[data-fullsync]");
  if (!b) return;
  const what = b.dataset.fullsync.split(",");
  const ok = await askDialog({ icon: ["download", "blue"], title: "Importer tout l'historique ?",
    text: (what.includes("messages") ? "Toutes les conversations Messenger et Instagram, avec tous leurs messages" : "") + (what.length > 1 ? ", et " : "") + (what.includes("comments") ? "tous les commentaires (et leurs réponses) des publications des 2 dernières années" : "")
      + " sont relus chez Meta. Cela peut prendre plusieurs minutes : gardez la page ouverte. S'il s'arrête avant la fin, relancez-le simplement.",
    ok: "Importer tout", cancel: "Annuler" });
  if (!ok) return;
  b.disabled = true;
  try {
    const r = await metaSyncLots({ only: what, full: true }, n => { b.innerHTML = `<span class="spin dark"></span> Import en cours… lot ${n} (gardez la page ouverte)`; });
    const t = r.tot;
    toast(r.ok ? `Historique importé (${num(r.lots)} lot${r.lots > 1 ? "s" : ""}) : ${num(t.added)} nouveauté(s), ${num(t.updated)} mise(s) à jour` : "Import terminé avec des erreurs : détail dans Connexions › Historique", r.ok ? "" : "err");
    try { localStorage.setItem("mk_hist", "all"); } catch (err) {}
    S.cache.clear();
    route();
  } catch (err) { toast(err.message, "err"); b.disabled = false; b.innerHTML = `${ic("download", "sm")} Importer tout l'historique`; }
});

/* ================================================================ vue : Messages (Messenger, Instagram) */
const HEAT = { chaud: ["Chaud", "crit"], tiede: ["Tiède", "warn"], froid: ["Froid", ""] };
const PLAT = { messenger: ["Messenger", "message", "blue"], instagram: ["Instagram", "share", "pink"] };
const CONV_FILTERS = [["all", "Toutes"], ["attente", "Sans réponse"], ["repondues", "Répondues"], ["chaud", "Chaudes"], ["coordonnees", "Avec coordonnées"], ["sans", "Sans prospect"], ["prospects", "Prospects créés"], ["traite", "Traitées"], ["ignore", "Ignorées"]];
/** Sans réponse : le dernier message vient de la personne, et la conversation n'est ni traitée ni ignorée. */
const convWaiting = c => !+c.last_from_me && c.status !== "ignore" && c.status !== "traite";
const convWho = c => c.name || (c.who_username ? "@" + c.who_username : "Inconnu");
const convIn = (f, c) => f === "attente" ? convWaiting(c) : f === "repondues" ? !!+c.last_from_me && c.status !== "ignore" : f === "chaud" ? c.heat === "chaud" && c.status !== "ignore" : f === "coordonnees" ? !!(c.phone || c.email) && c.status !== "ignore"
  : f === "sans" ? !c.contact_id && c.status !== "ignore" : f === "prospects" ? !!c.contact_id : f === "traite" ? c.status === "traite" : f === "ignore" ? c.status === "ignore" : c.status !== "ignore";
const minutes = v => v == null ? "—" : v < 60 ? num(v) + " min" : v < 1440 ? num(v / 60, 1) + " h" : num(v / 1440, 1) + " j";
const initial = s => (String(s || "?").replace(/^@/, "").trim().charAt(0) || "?").toUpperCase();

VIEWS["leads/messages"] = { range: true, fn: async param => {
  const [from, to] = curRange();
  const all = histAll();
  const d = await api("conversations", all ? { all: 1 } : { from, to });
  S.convs = d.rows;
  const st = d.stats, f = CONV_FILTERS.some(x => x[0] === param) ? param : "all";
  if (!d.meta.connected) return empty("Les messages arrivent de Meta. Connectez votre Page Facebook (et son compte Instagram) dans Connexions : les conversations Messenger et Instagram s'afficheront ici, analysées.", `<a class="btn pri" href="#connexions/meta">${ic("link", "sm")} Relier Facebook et Instagram</a>`, { icon: ["message", "teal"] });
  const bar = `<div class="toolbar">${histToggle()}<span class="grow"></span>${fullBtn("messages")}</div>`;
  if (!d.rows.length) return bar + `${!d.meta.messages ? `<div class="banner warn">${ic("alert", "sm")}<span>L'import des messages est désactivé : activez-le dans <a href="#connexions/meta">Connexions</a> › étape 3.</span></div>` : ""}`
    + empty(all ? "Aucune conversation importée. Lancez « Importer tout l'historique » : il faut les autorisations pages_messaging et instagram_manage_messages dans votre configuration Facebook Login for Business." : "Aucune conversation sur la période. Choisissez « Tout l'historique » pour voir toutes les conversations importées, ou lancez une synchronisation dans Connexions.", `<a class="btn pri" href="#connexions/meta">${ic("trend", "sm")} Synchroniser</a>`, { icon: ["message", "teal"] });
  const t = (l, v, sub, m, cls = "") => `<div class="kpi ${cls}">${tile(m[0], m[1])}<small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  const counts = Object.fromEntries(CONV_FILTERS.map(([k]) => [k, d.rows.filter(c => convIn(k, c)).length]));
  return bar + `<div class="kpis k6">${t("Conversations", num(st.total), all ? "tout l'historique" : esc(rangeLabel()), ["message", "teal"])}${t("Déjà répondues", num(st.replied), pct(ratio(st.replied, st.total)) + " des conversations", ["check-circle", "green"])}${t("Sans réponse, à traiter", num(st.waiting), st.waiting ? "le dernier message vient de la personne" : "tout le monde a eu une réponse", ["clock", "amber"], st.waiting ? "warn" : "")}`
    + `${t("Conversations chaudes", num(st.hot), "intention forte ou coordonnées laissées", ["zap", "pink"])}`
    + `${t("Prospects créés", num(st.prospects), pct(ratio(st.prospects, st.total)) + " des conversations", ["user-plus", "blue"])}${t("Première réponse", minutes(st.reply_median), "délai médian", ["send", "violet"])}</div>`
    + `<div class="grid3">${card("💬 Sujets", mapBars(st.by_category, "inq_categories", st.total))}${card("🔥 Chaleur", hbars(["chaud", "tiede", "froid"].filter(k => st.by_heat[k]).map(k => ({ label: HEAT[k][0], value: st.by_heat[k], sub: pct(st.by_heat[k] / st.total) }))))}${card("👥 Profils détectés", hbars(Object.entries(st.by_profile).map(([k, v]) => ({ label: k === "autre" ? "Non détecté" : L("contact_types", k), value: v, sub: pct(v / st.total) }))))}</div>`
    + `<div class="chips">${CONV_FILTERS.map(([k, l]) => `<a class="chip${f === k ? " on" : ""}" href="#leads/messages${k === "all" ? "" : "/" + k}">${esc(l)} (${num(counts[k])})</a>`).join("")}</div>`
    + `<section class="card"><div class="card-h"><h2>${tile("message", "teal", "sm")}Conversations</h2><div class="ctrl"><label class="field-search">${ic("search", "sm")}<input type="search" id="cvQ" placeholder="Nom, message, téléphone…" aria-label="Rechercher une conversation"></label></div></div><div id="cvList"></div></section>`;
}, after: param => {
  const f = CONV_FILTERS.some(x => x[0] === param) ? param : "all";
  const list = $("#cvList");
  if (!list) return;
  let shown = MORE;
  const draw = () => {
    const q = foldTxt($("#cvQ").value || "");
    const rows = S.convs.filter(c => convIn(f, c) && (!q || foldTxt([convWho(c), c.snippet, c.phone, c.email, c.city].join(" ")).includes(q)));
    list.innerHTML = rows.length ? `<div class="inbox">${rows.slice(0, shown).map(c => {
      const p = PLAT[c.platform] || PLAT.messenger, h = HEAT[c.heat] || HEAT.froid;
      return `<div class="cv${convWaiting(c) ? " waiting" : ""}" data-cv="${c.id}">
        <span class="av t-${p[2]}">${esc(initial(convWho(c)))}<i>${ic(p[1], "xs")}</i></span>
        <span class="cv-main"><span class="cv-top"><b>${esc(convWho(c))}</b><span class="muted small">${esc(p[0])} · ${esc(ago(Date.parse(String(c.updated).replace(" ", "T")) / 1000))}</span></span>
          <span class="cv-snip">${+c.last_from_me ? `<span class="muted">Vous : </span>` : ""}<bdi dir="auto">${esc(c.snippet || "")}</bdi></span>
          <span class="cv-tags"><span class="pill ${h[1]}">${h[0]} · ${num(c.score)}</span>${c.category && c.category !== "autre" ? `<span class="pill blue">${esc(L("inq_categories", c.category))}</span>` : ""}${c.profile ? `<span class="pill">${esc(L("contact_types", c.profile))}</span>` : ""}${c.phone ? `<span class="chip static">${ic("phone", "xs")} ${esc(c.phone)}</span>` : ""}${c.email ? `<span class="chip static">${ic("mail", "xs")} ${esc(c.email)}</span>` : ""}${c.city ? `<span class="chip static">${ic("globe", "xs")} ${esc(c.city)}</span>` : ""}${convWaiting(c) ? '<span class="pill warn">Sans réponse</span>' : +c.last_from_me ? '<span class="pill good">Répondue</span>' : c.status === "traite" ? '<span class="pill">Traitée</span>' : ""}</span></span>
        <span class="cv-act">${c.contact_id ? `<a class="btn sm" href="javascript:void 0" data-edit="contacts:${c.contact_id}">${ic("check-circle", "sm")} Prospect</a>` : c.status === "ignore" ? `<button type="button" class="btn sm ghost" data-cvst="${c.id}:a_traiter">Rétablir</button>` : `<button type="button" class="btn sm pri" data-cvp="${c.id}">${ic("user-plus", "sm")} Créer le prospect</button>`}
          <button type="button" class="btn sm" data-cvo="${c.id}">${ic("eye", "sm")} Lire</button>${c.status === "a_traiter" && !+c.last_from_me ? `<button type="button" class="btn sm ghost" data-cvst="${c.id}:traite">${ic("check", "sm")} Traitée</button>` : c.status === "traite" ? `<button type="button" class="btn sm ghost" data-cvst="${c.id}:a_traiter">Rétablir</button>` : ""}</span></div>`;
    }).join("")}</div>${moreBtn(shown, rows.length)}` : empty("Aucune conversation ne correspond à ce filtre.", "", { icon: ["search", "violet"] });
  };
  $("#cvQ").addEventListener("input", () => { shown = MORE; draw(); });
  list.addEventListener("click", async e => {
    if (e.target.closest("[data-more]")) { shown += MORE; return draw(); }
    const o = e.target.closest("[data-cvo]"), p = e.target.closest("[data-cvp]"), s2 = e.target.closest("[data-cvst]");
    if (o) return openConversation(+o.dataset.cvo);
    if (p) return openProspect(+p.dataset.cvp);
    if (s2) { const [id, st] = s2.dataset.cvst.split(":"); try { await api("conv_status", {}, { id: +id, status: st }); S.cache.clear(); route(); } catch (err) { toast(err.message, "err"); } }
  });
  draw();
} };

/** Fil de discussion, avec l'analyse et les actions. */
async function openConversation(id) {
  let d;
  try { d = await api("conversation", { id }); } catch (err) { return toast(err.message, "err"); }
  const c = d.conversation, p = PLAT[c.platform] || PLAT.messenger, h = HEAT[c.heat] || HEAT.froid;
  const row = (l, v) => v ? `<div class="rv"><span class="rv-l">${l}</span><span class="rv-v">${v}</span></div>` : "";
  openModal(`<div class="thread-h"><span class="av t-${p[2]}">${esc(initial(c.name))}<i>${ic(p[1], "xs")}</i></span><div><h2 style="margin:0">${esc(c.name)}</h2><span class="muted small">${esc(p[0])} · ${num(c.message_count)} message(s)${c.who_username ? " · @" + esc(c.who_username) : ""}</span></div></div>
    <div class="thread-grid"><div class="thread">${d.messages.map(m => `<div class="bub${+m.from_me ? " me" : ""}"><span dir="auto">${esc(m.text)}</span><small>${esc(fdate(String(m.at).slice(0, 10)) + " · " + String(m.at).slice(11, 16))}</small></div>`).join("") || '<p class="muted">Aucun message importé.</p>'}</div>
    <div class="thread-side"><h3 style="margin-top:0">Analyse</h3><div class="rv-list">${row("Chaleur", `<span class="pill ${h[1]}">${h[0]} · ${num(c.score)}/100</span>`)}${row("Sujet", esc(L("inq_categories", c.category || "autre")))}${row("Profil", c.profile ? esc(L("contact_types", c.profile)) : '<span class="muted">non détecté</span>')}${row("Question posée", +c.is_question ? "Oui" : "Non")}${row("Intention", +c.interest ? "Forte (veut investir, être rappelé…)" : "—")}${row("Téléphone", esc(c.phone))}${row("Email", esc(c.email))}${row("Wilaya", esc(c.city))}${row("1re réponse", c.reply_minutes != null ? minutes(+c.reply_minutes) : "")}</div>
      <div class="toolbar" style="margin:12px 0 0;flex-direction:column;align-items:stretch">${d.contact ? `<button type="button" class="btn" data-edit="contacts:${d.contact.id}">${ic("check-circle", "sm")} Voir le prospect : ${esc(d.contact.name)}</button>` : `<button type="button" class="btn pri" id="thProspect">${ic("user-plus", "sm")} Créer le prospect</button>`}
        <a class="btn" href="${esc(c.link)}" target="_blank" rel="noopener">${ic("send", "sm")} Répondre dans Meta Business Suite</a>
        ${d.contact ? "" : c.status === "ignore" ? `<button type="button" class="btn ghost" id="thIgn" data-st="a_traiter">Rétablir dans la liste</button>` : `<button type="button" class="btn ghost" id="thIgn" data-st="ignore">Ignorer (pas un prospect)</button>`}</div>
      <p class="small muted" style="margin:10px 0 0">Le tableau de bord lit les messages, il n'en envoie jamais : répondez depuis Meta Business Suite.</p></div></div>
    <div class="form-foot"><button type="button" class="btn" data-close>Fermer</button></div>`);
  const th = $(".thread"); if (th) th.scrollTop = th.scrollHeight;
  if ($("#thProspect")) $("#thProspect").addEventListener("click", () => openProspect(id, d));
  if ($("#thIgn")) $("#thIgn").addEventListener("click", async e => {
    try { await api("conv_status", {}, { id, status: e.target.dataset.st }); closeModal(); toast(e.target.dataset.st === "ignore" ? "Conversation ignorée" : "Conversation rétablie"); S.cache.clear(); route(); } catch (err) { toast(err.message, "err"); }
  });
}

/** Formulaire du prospect d'une conversation, prérempli avec ce que l'analyse a trouvé (à relire avant d'enregistrer). */
async function openProspect(id, d = null) {
  if (!d) { try { d = await api("conversation", { id }); } catch (err) { return toast(err.message, "err"); } }
  const c = d.conversation;
  prospectDialog({ endpoint: "conv_prospect", id, source: c.platform === "instagram" ? "instagram" : (channelOf("messenger") ? "messenger" : "facebook"),
    name: c.who_name || (c.who_username ? "@" + c.who_username : ""), profile: c.profile, phone: c.phone, email: c.email, city: c.city,
    intro: `Prérempli avec la conversation ${c.platform === "instagram" ? "Instagram" : "Messenger"}`, notes: "Le lien de la conversation et ses derniers messages sont ajoutés tout seuls aux notes du prospect.", what: "Conversation reliée" });
}
/** Formulaire du prospect d'un commentaire. */
function openCommentProspect(c) {
  prospectDialog({ endpoint: "comment_prospect", id: c.id, source: c.platform === "instagram" ? "instagram" : "facebook",
    name: c.name === "Nom masqué par Meta" ? "" : (c.who_name || (c.who_username ? "@" + c.who_username : "")), profile: c.profile, phone: c.phone, email: c.email, city: c.city,
    intro: `Prérempli avec le commentaire ${c.platform === "instagram" ? "Instagram" : "Facebook"}${c.name === "Nom masqué par Meta" ? " (Meta cache le nom : ouvrez le commentaire pour le lire)" : ""}`, notes: "Le lien du commentaire et son texte sont ajoutés tout seuls aux notes du prospect.", what: "Commentaire relié" });
}
function prospectDialog(o) {
  const opts = (en, v, empty) => (empty ? `<option value="">${esc(empty)}</option>` : "") + Object.entries(EN(en)).map(([k, l]) => `<option value="${esc(k)}"${k === v ? " selected" : ""}>${esc(l)}</option>`).join("");
  openModal(`<div class="imp">${tile("user-plus", "blue")}<h2>Créer le prospect</h2>
    <p class="muted" style="margin-top:-4px">${esc(o.intro)} : vérifiez, complétez, puis enregistrez. Si la même personne existe déjà (même téléphone ou email), elle est reliée au lieu d'être recréée.</p>
    <form id="pForm" class="form" novalidate>
      <div class="fld"><label for="pN">Nom complet <span class="req">*</span></label><input type="text" id="pN" value="${esc(o.name)}" placeholder="Nom de la personne"></div>
      <div class="fld"><label for="pT">Profil</label><select id="pT">${opts("contact_types", o.profile || S.boot.meta.default_profile || "investisseur")}</select></div>
      <div class="fld"><label for="pP">Téléphone</label><input type="text" id="pP" value="${esc(o.phone)}" placeholder="0X XX XX XX XX"></div>
      <div class="fld"><label for="pE">Email</label><input type="email" id="pE" value="${esc(o.email)}"></div>
      <div class="fld"><label for="pC">Wilaya / ville</label><input type="text" id="pC" value="${esc(o.city)}"></div>
      <div class="fld"><label for="pA">Commercial responsable</label><select id="pA">${opts("commercials", "", "Non assigné")}</select></div>
      <div class="fld full"><label for="pO">Note (facultatif)</label><textarea id="pO" rows="2" placeholder="ex. veut investir 100 000 DA, rappeler après 18 h"></textarea><small>${esc(o.notes)}</small></div>
    </form><p class="form-err" id="pErr"></p>
    <div class="form-foot"><button type="button" class="btn" data-close>Annuler</button><button type="button" class="btn pri" id="pGo">${ic("user-plus", "sm")} Créer le prospect</button></div></div>`);
  $("#pN").focus();
  $("#pGo").addEventListener("click", async e => {
    if (!$("#pN").value.trim()) { $("#pErr").textContent = "Donnez au moins un nom (même provisoire) au prospect."; return; }
    e.target.disabled = true;
    try {
      const r = await api(o.endpoint, {}, { id: o.id, contact: { name: $("#pN").value, type: $("#pT").value, phone: $("#pP").value, email: $("#pE").value, city: $("#pC").value, assigned_to: $("#pA").value, notes: $("#pO").value, source: o.source } });
      closeModal();
      toast(r.linked ? `${o.what} au lead existant « ${r.contact.name} »` : `Prospect « ${r.contact.name} » créé dans Leads & Contacts`);
      S.cache.clear();
      route();
    } catch (err) { $("#pErr").textContent = err.message; e.target.disabled = false; }
  });
}

/* ================================================================ vue : Commentaires (Facebook, Instagram) */
const CPLAT = { facebook: ["Facebook", "share", "blue"], instagram: ["Instagram", "share", "pink"] };
const CMT_FILTERS = [["all", "Tous"], ["attente", "Sans réponse"], ["repondus", "Répondus"], ["questions", "Questions"], ["coordonnees", "Avec coordonnées"], ["facebook", "Facebook"], ["instagram", "Instagram"], ["prospects", "Prospects créés"], ["traite", "Traités"], ["ignore", "Ignorés"]];
const cmtWaiting = c => !+c.from_me && !+c.page_replied && c.status === "a_traiter" && !c.contact_id;
const cmtIn = (f, c) => f === "attente" ? cmtWaiting(c) : f === "repondus" ? !!+c.page_replied && !+c.from_me : f === "questions" ? !!+c.is_question && c.status !== "ignore" : f === "coordonnees" ? !!(c.phone || c.email) && c.status !== "ignore"
  : f === "facebook" || f === "instagram" ? c.platform === f && c.status !== "ignore" : f === "prospects" ? !!c.contact_id
  : f === "traite" ? c.status === "traite" && !+c.from_me : f === "ignore" ? c.status === "ignore" : c.status !== "ignore";
const atTs = v => Date.parse(String(v).replace(" ", "T")) / 1000;

VIEWS["leads/comments"] = { range: true, fn: async param => {
  const [from, to] = curRange();
  const all = histAll();
  const d = await api("comments", all ? { all: 1 } : { from, to });
  S.cmts = d;
  const f = CMT_FILTERS.some(x => x[0] === param) ? param : "all";
  if (!d.meta.connected) return empty("Les commentaires arrivent de Meta. Connectez votre Page Facebook et son compte Instagram dans Connexions : tous les commentaires de vos publications s'afficheront ici, avec leurs réponses.", `<a class="btn pri" href="#connexions/meta">${ic("link", "sm")} Relier Facebook et Instagram</a>`, { icon: ["message", "violet"] });
  const bar = `<div class="toolbar">${histToggle()}<span class="grow"></span>${fullBtn("comments")}</div>`;
  if (!d.rows.length) return bar + empty(all ? "Aucun commentaire importé pour l'instant. Lancez « Importer tout l'historique » : tous les commentaires des publications des 2 dernières années seront récupérés, avec leurs réponses." : "Aucun commentaire sur la période. Choisissez « Tout l'historique » pour voir tous les commentaires importés, ou lancez une synchronisation dans Connexions.", "", { icon: ["message", "violet"] });
  const st = d.stats;
  const t = (l, v, sub, m, cls = "") => `<div class="kpi ${cls}">${tile(m[0], m[1])}<small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  const counts = Object.fromEntries(CMT_FILTERS.map(([k]) => [k, d.rows.filter(c => cmtIn(k, c)).length]));
  return bar + `<div class="kpis k6">${t("Commentaires", num(st.total), all ? "tout l'historique" : esc(rangeLabel()), ["message", "violet"])}${t("Sans réponse, à traiter", num(st.waiting), st.waiting ? "la Page n'a pas encore répondu" : "la Page a répondu à tout", ["clock", "amber"], st.waiting ? "warn" : "")}`
    + `${t("Déjà répondus", num(st.replied), pct(ratio(st.replied, st.total)) + " des commentaires (réponse de la Page)", ["check-circle", "green"])}${t("Questions", num(st.questions), pct(ratio(st.questions, st.total)) + " des commentaires", ["info", "teal"])}`
    + `${t("Avec téléphone ou email", num(st.with_contact), "coordonnées laissées en public", ["phone", "blue"])}${t("Prospects créés", num(st.prospects), pct(ratio(st.prospects, st.total)) + " des commentaires", ["user-plus", "pink"])}</div>`
    + `<div class="grid3">${card("💬 Sujets", mapBars(st.by_category, "inq_categories", st.total))}${card("📱 Réseaux", hbars(Object.entries(st.by_platform).map(([k, v]) => ({ label: (CPLAT[k] || [k])[0], value: v, sub: pct(v / st.total) }))))}${card("👥 Profils détectés", hbars(Object.entries(st.by_profile).map(([k, v]) => ({ label: k === "autre" ? "Non détecté" : L("contact_types", k), value: v, sub: pct(v / st.total) }))))}</div>`
    + `<div class="chips">${CMT_FILTERS.map(([k, l]) => `<a class="chip${f === k ? " on" : ""}" href="#leads/comments${k === "all" ? "" : "/" + k}">${esc(l)} (${num(counts[k])})</a>`).join("")}</div>`
    + `<section class="card"><div class="card-h"><h2>${tile("message", "violet", "sm")}Commentaires et réponses</h2><div class="ctrl"><label class="field-search">${ic("search", "sm")}<input type="search" id="cmQ" placeholder="Nom, commentaire, publication…" aria-label="Rechercher un commentaire"></label></div></div>`
    + (d.stats.total && d.rows.some(c => c.name === "Nom masqué par Meta") ? `<p class="small muted" style="margin:-6px 0 8px">« Nom masqué par Meta » : Meta ne donne pas toujours le nom des personnes qui commentent sur Facebook. Ouvrez le commentaire avec « Répondre » pour le voir.</p>` : "")
    + `<div id="cmList"></div></section>`;
}, after: param => {
  const f = CMT_FILTERS.some(x => x[0] === param) ? param : "all";
  const list = $("#cmList");
  if (!list) return;
  const reps = {};
  for (const r of S.cmts.replies) (reps[r.parent_ext] = reps[r.parent_ext] || []).push(r);
  let shown = MORE;
  const row = c => {
    const p = CPLAT[c.platform] || CPLAT.facebook, masked = c.name === "Nom masqué par Meta", wait = cmtWaiting(c), link = c.link || c.post_link, post = c.post_topic || c.post_text || "";
    const rs = reps[c.ext_id] || [];
    return `<div class="cv cm${wait ? " waiting" : ""}">
      <span class="av t-${p[2]}">${esc(masked ? "?" : initial(c.name))}<i>${ic(p[1], "xs")}</i></span>
      <span class="cv-main"><span class="cv-top"><b${masked ? ' class="muted"' : ""}>${esc(c.name)}</b><span class="muted small">${esc(p[0])} · ${esc(ago(atTs(c.at)))}${+c.likes ? ` · ${ic("heart", "xs")} ${num(c.likes)}` : ""}</span></span>
        <span class="cm-text"><bdi dir="auto">${esc(c.text)}</bdi></span>
        ${post || c.post_link ? `<span class="cm-post">${ic("file", "xs")} Sur ${c.post_link ? `<a href="${esc(c.post_link)}" target="_blank" rel="noopener">« ${esc(post.slice(0, 90) || "la publication")} »</a>` : `« ${esc(post.slice(0, 90))} »`}</span>` : ""}
        <span class="cv-tags">${+c.is_question ? `<span class="pill blue">Question · ${esc(L("inq_categories", c.category || "autre"))}</span>` : ""}${c.profile ? `<span class="pill">${esc(L("contact_types", c.profile))}</span>` : ""}${c.phone ? `<span class="chip static">${ic("phone", "xs")} ${esc(c.phone)}</span>` : ""}${c.email ? `<span class="chip static">${ic("mail", "xs")} ${esc(c.email)}</span>` : ""}${c.city ? `<span class="chip static">${ic("globe", "xs")} ${esc(c.city)}</span>` : ""}${+c.page_replied ? '<span class="pill good">La Page a répondu</span>' : wait ? '<span class="pill warn">Sans réponse</span>' : ""}${c.status === "traite" && !+c.page_replied && !c.contact_id && !+c.from_me ? '<span class="pill">Traité</span>' : ""}${c.status === "ignore" ? '<span class="pill">Ignoré</span>' : ""}</span>
        ${rs.length ? `<span class="cm-reps">${rs.map(r => `<span class="cm-rep${+r.from_me ? " me" : ""}"><b>${esc(r.name)}</b> <bdi dir="auto">${esc(r.text)}</bdi> <small class="muted">· ${esc(ago(atTs(r.at)))}</small></span>`).join("")}</span>` : ""}</span>
      <span class="cv-act">${c.contact_id ? `<a class="btn sm" href="javascript:void 0" data-edit="contacts:${c.contact_id}">${ic("check-circle", "sm")} Prospect</a>` : +c.from_me ? "" : `<button type="button" class="btn sm pri" data-cmp="${c.id}">${ic("user-plus", "sm")} Créer le prospect</button>`}
        ${link ? `<a class="btn sm" href="${esc(link)}" target="_blank" rel="noopener">${ic("send", "sm")} Répondre</a>` : ""}
        ${+c.from_me || c.contact_id ? "" : c.status === "a_traiter" ? `<button type="button" class="btn sm ghost" data-cmst="${c.id}:traite">${ic("check", "sm")} Traité</button><button type="button" class="btn sm ghost" data-cmst="${c.id}:ignore">Ignorer</button>` : `<button type="button" class="btn sm ghost" data-cmst="${c.id}:a_traiter">Rétablir</button>`}</span></div>`;
  };
  const draw = () => {
    const q = foldTxt($("#cmQ").value || "");
    const rows = S.cmts.rows.filter(c => cmtIn(f, c) && (!q || foldTxt([c.name, c.text, c.post_topic, c.post_text, c.phone, c.email, c.city, ...(reps[c.ext_id] || []).map(r => r.text)].join(" ")).includes(q)));
    list.innerHTML = rows.length ? `<div class="inbox">${rows.slice(0, shown).map(row).join("")}</div>${moreBtn(shown, rows.length)}` : empty("Aucun commentaire ne correspond à ce filtre.", "", { icon: ["search", "violet"] });
  };
  $("#cmQ").addEventListener("input", () => { shown = MORE; draw(); });
  list.addEventListener("click", async e => {
    if (e.target.closest("[data-more]")) { shown += MORE; return draw(); }
    const pr = e.target.closest("[data-cmp]"), stb = e.target.closest("[data-cmst]");
    if (pr) return openCommentProspect(S.cmts.rows.find(c => +c.id === +pr.dataset.cmp));
    if (stb) {
      const [id, st] = stb.dataset.cmst.split(":");
      try {
        await api("comment_status", {}, { id: +id, status: st });
        const c = S.cmts.rows.find(x => +x.id === +id);
        if (c) c.status = st;
        toast(st === "ignore" ? "Commentaire ignoré" : st === "traite" ? "Commentaire marqué comme traité" : "Commentaire rétabli");
        S.cache.clear();
        draw();
      } catch (err) { toast(err.message, "err"); }
    }
  });
  draw();
} };

VIEWS["leads/questions"] = { range: true, fn: async param => {
  const all = await rangeList("inquiries");
  const sel = param || "all";
  const rows = all.filter(inCat(sel, "source"));
  const open = rows.filter(r => ["nouveau", "en_cours"].includes(r.status)).length;
  const cat = {};
  for (const r of rows) cat[r.category] = (cat[r.category] || 0) + 1;
  const t = (l, v, sub, m, cls = "") => `<div class="kpi ${cls}">${tile(m[0], m[1])}<small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  return catChips("leads/questions", sel, all, "source")
    + `<div class="kpis">${t("Questions reçues", num(rows.length), esc(rangeLabel()), ["message", "teal"])}${t("En attente de réponse", num(open), open ? "à traiter en priorité" : "tout est traité", ["clock", "amber"], open ? "warn" : "")}${t("Taux de réponse", pct(ratio(rows.length - open, rows.length)), "&nbsp;", ["check-circle", "green"])}</div>`
    + `<div class="grid2">${card("💬 Sujets des questions", mapBars(cat, "inq_categories", rows.length))}${card("📣 Canaux d'arrivée", mapBars(rows.reduce((a, r) => (a[r.source] = (a[r.source] || 0) + 1, a), {}), "sources", rows.length))}</div>`
    + card("💬 Questions", table(rows, inquiryCols(), { entity: "inquiries", emptyMsg: "Aucune question sur la période. Les questions reçues par message, téléphone ou formulaire apparaîtront ici dès que vous les enregistrez." }), addBtn("inquiries", "Nouvelle question", null, "btn sm pri") + exportBtn("inquiries"));
} };

VIEWS["social/overview"] = { range: true, fn: async () => {
  const [from, to] = curRange();
  const [r, perf, posts, leads] = await Promise.all([rangeReport(), cached("performance", { from, to }), rangeList("posts"), rangeList("contacts")]);
  const soc = socialKeys();
  const t = r.social.totals, p = r.prev.social.totals;
  const sp = posts.filter(x => soc.includes(x.platform));
  const sl = leads.filter(x => soc.includes(x.source));
  const k = (label, v, pv, m, fmt = "int") => kpiTile({ key: "", label, value: v, prev: pv, fmt, better: "up" }, "", m);
  const socCat = perf.perf.categories.find(c => c.key === "social");
  const sups = {};
  for (const ch of socCat ? socCat.channels : []) for (const x of ch.supports) if (x.key) { const s = sups[x.key] = sups[x.key] || { label: x.label, reach: 0, engagement: 0 }; s.reach += x.reach; s.engagement += x.engagement; }
  const supBars = hbars(Object.values(sups).filter(x => x.reach > 0).map(x => ({ label: x.label, value: x.engagement / x.reach, tip: `Portée ${num(x.reach)} · Engagement ${num(x.engagement)}` })).sort((a, b) => b.value - a.value).slice(0, 8), v => pct(v, 1));
  if (!soc.length) return empty("Aucun réseau social configuré. Ajoutez des canaux dans la catégorie Réseaux sociaux pour suivre leurs résultats ici.", `<a class="btn pri" href="#comm/taxonomy">${ic("layers", "sm")} Gérer les canaux</a>`, { icon: ["share", "violet"] });
  return `<div class="kpis">${k("Portée des comptes", t.reach, p.reach, ["eye", "violet"])}${k("Engagement", t.engagement, p.engagement, ["heart", "pink"])}${k("Taux d'engagement", t.eng_rate, p.eng_rate, ["trend", "green"], "pct")}${k("Nouveaux abonnés", t.new_followers, p.new_followers, ["user-plus", "blue"])}`
    + `<div class="kpi">${tile("users", "teal")}<small>Abonnés (total)</small><b>${num(t.followers)}</b><span class="d">dernier relevé</span></div>`
    + `<a class="kpi" href="#comm/actions/social">${tile("megaphone", "violet")}<small>Actions sur les réseaux</small><b>${num(sp.length)}</b><span class="d">voir les actions</span></a>`
    + `<a class="kpi" href="#leads/contacts/social">${tile("target", "orange")}<small>Leads venus des réseaux</small><b>${num(sl.length)}</b><span class="d">voir les leads</span></a></div>`
    + card("📱 Résultats par réseau social", socialTable(r.social) + `<p class="small muted" style="margin:10px 0 0">Chiffres des comptes, relevés chaque semaine dans les statistiques de chaque plateforme (onglet Relevés des comptes).</p>`, addBtn("social_stats", "Saisir un relevé", null, "btn sm pri"))
    + `<div class="grid2">${card("📈 Formats qui engagent le plus", Object.keys(sups).length ? supBars : empty("Aucun support renseigné. Indiquez le support de vos actions (Reel, carrousel…) pour savoir lequel engage le plus."), `<a class="btn sm" href="#comm/performance">Performance</a>`)}`
    + `${card("📣 Dernières actions sur les réseaux", table(sp.slice(0, 6), [{ l: "Date", h: x => fdate(x.date) }, { l: "Réseau", h: x => chCell(x.platform, x.format) }, { l: "Contenu", wrap: 1, h: x => esc(x.topic) }, { l: "Portée", n: 1, h: x => num(x.reach) }], { entity: "posts", emptyMsg: "Aucune action sur les réseaux. Enregistrez votre première publication avec l'assistant." }), addBtn("posts", "Nouvelle action", { category: "social" }, "btn sm"))}</div>`;
} };

VIEWS["social/stats"] = { range: true, fn: async () => {
  const rows = await rangeList("social_stats");
  return `<p class="muted" style="margin-top:0">Une ligne = les chiffres d'un compte sur un jour ou une semaine, datés du dernier jour couvert · ${esc(rangeLabel())}.</p>`
    + card("📈 Relevés des comptes", table(rows, [col("social_stats", "date", { h: x => fdate(x.date) }), { l: "Réseau social", h: x => chCell(x.platform) }, col("social_stats", "followers"), col("social_stats", "new_followers"), col("social_stats", "reach"), col("social_stats", "impressions"), { l: "Engagement", n: 1, h: x => num(x.engagement) }, col("social_stats", "clicks")], { entity: "social_stats", emptyMsg: "Aucun relevé sur la période. Saisissez chaque semaine les abonnés, la portée et l'engagement de chaque compte pour suivre leur évolution." }), addBtn("social_stats", "Saisir un relevé", null, "btn sm pri") + exportBtn("social_stats"));
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
      { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a>${metaTag(c)}<div class="sub">${esc(L("ad_channels", c.platform))} · ${esc(L("ad_objectives", c.objective))}</div>` },
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

VIEWS["ads/budgets"] = { fn: async () => {
  const rows = (await cached("campaigns_overview")).rows.filter(c => c.status !== "brouillon");
  const act = rows.filter(c => c.status === "active");
  const b = act.reduce((a, c) => a + c.budget, 0), s = act.reduce((a, c) => a + c.spent, 0);
  const late = act.filter(c => c.target_value > 0 && c.elapsed != null && ratio(c.achieved, c.target_value) < c.elapsed * 0.7).length;
  const t = (l, v, sub, m, cls = "") => `<div class="kpi ${cls}">${tile(m[0], m[1])}<small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  return `<div class="kpis">${t("Budget prévu (actives)", money(b), num(act.length) + " campagne(s) active(s)", ["wallet", "violet"])}${t("Dépensé", money(s), pct(ratio(s, b)) + " du budget", ["coins", "orange"])}${t("Reste à dépenser", money(b - s), b - s < 0 ? "budget dépassé" : "&nbsp;", ["chart", "green"], b - s < 0 ? "alert" : "")}${t("Objectifs en retard", num(late), late ? "à regarder de près" : "tout est dans les temps", ["target", late ? "amber" : "teal"], late ? "warn" : "")}</div>`
    + card("💰 Budgets", budgetsTable(rows) + `<p class="small muted" style="margin:8px 0 0">La jauge montre la part du budget dépensée ; le repère vertical, la part de la durée déjà écoulée. Une jauge très en avance sur le repère : la campagne dépense trop vite.</p>`, addBtn("ad_results", "Saisir une dépense", null, "btn sm pri"))
    + card("🎯 Objectifs des campagnes", table(rows, [
      { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a><div class="sub">${esc(L("ad_objectives", c.objective))}</div>` },
      { l: "Indicateur suivi", h: c => esc(L("target_metrics", c.target_metric)) },
      { l: "Avancement (repère = durée écoulée)", h: c => targetCell(c.achieved, c.target_value, c.elapsed, L("target_metrics", c.target_metric).toLowerCase()) },
      { l: "Durée écoulée", n: 1, h: c => c.elapsed == null ? "—" : pct(c.elapsed) + (c.days_left != null && c.status === "active" ? `<div class="sub">${c.days_left} j restant(s)</div>` : "") },
      { l: "", h: c => `<button type="button" class="btn sm ghost" data-edit="campaigns:${c.id}">${ic("edit", "sm")} Modifier l'objectif</button>` },
    ], { emptyMsg: "Aucune campagne lancée. Créez une campagne et fixez-lui un objectif chiffré pour suivre son avancement ici." }), NEW_CAMPAIGN.replace('class="btn pri"', 'class="btn sm pri"'));
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
  const info = [["Canal publicitaire", L("ad_channels", c.platform)], ["Objectif marketing", L("ad_objectives", c.objective)], ["Cible", L("audiences", c.audience) || "—"], ["Dates", fdate(c.start) + " → " + (c.end ? fdate(c.end) : "sans fin")], ["Landing page", refName("pages", c.page_id) || "—"], ["Funnel", refName("funnels", c.funnel_id) || "—"]];
  return `<div class="toolbar"><a class="btn ghost" href="#ads/campaigns">${ic("chev-l", "sm")} Campagnes</a><span class="grow"></span><button type="button" class="btn" data-edit="campaigns:${c.id}">${ic("edit", "sm")} Modifier la campagne</button>${addBtn("ad_results", "Saisir des résultats", { campaign_id: c.id }, "btn pri")}</div>`
    + card(`📢 ${esc(c.name)} <span class="pill ${c.status === "active" ? "good" : c.status === "pause" ? "warn" : ""}">${esc(L("campaign_status", c.status))}</span>`,
      `<div class="kpis" style="margin-bottom:8px">${info.map(([l, v]) => `<div class="kpi txt"><small>${esc(l)}</small><b>${esc(v)}</b></div>`).join("")}</div>${c.notes ? `<p class="sub">${esc(c.notes)}</p>` : ""}
      <div class="grid2"><div><h3>Objectif</h3>${targetCell(s.achieved, s.target_value, s.elapsed, L("target_metrics", s.target_metric).toLowerCase())}</div><div><h3>Budget</h3>${budgetCell(Object.assign({ name: c.name }, s))}<div class="sub">${money(s.spent)} dépensés sur ${money(s.budget)} · reste ${money(s.remaining)}</div></div></div>`)
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
VIEWS["email/performance"] = { range: true, fn: async () => {
  const rows = (await emailRows()).filter(e => e.status === "envoyee");
  const weak = e => e.sent >= 50 && e.open_rate != null && e.open_rate < 0.15;
  return emailKpis(rows) + card("📈 Performance des envois", table(rows, [
    { l: "Campagne", h: emailName },
    { l: "Envoyés", n: 1, h: e => num(e.sent) },
    { l: "Ouverture*", h: e => `<div class="mcell">${meter(e.open_rate)}<span>${pct(e.open_rate)}</span></div>` + (weak(e) ? '<span class="pill warn">Ouverture faible</span>' : "") },
    { l: "Clic", n: 1, h: e => pct(e.click_rate, 1) + `<div class="sub">${num(e.clicks)} clics</div>` },
    { l: "Clics / ouvertures", n: 1, h: e => pct(e.ctor, 1) },
    { l: "Objectif", wrap: 1, h: e => (e.objective ? `<div class="small">${esc(e.objective)}</div>` : "") + targetCell(+e.conversions, +e.target, null, "") },
    { l: "Désabonnements", n: 1, h: e => num(e.unsubscribes) + `<div class="sub">${pct(e.unsub_rate, 2)}</div>` },
  ], { entity: "emails", emptyMsg: "Aucun email envoyé sur la période. Les résultats apparaissent ici dès qu'une campagne passe au statut « Envoyée »." }) + ESTIMATE, NEW_EMAIL.replace('class="btn pri"', 'class="btn sm pri"'));
} };

/* ================================================================ vues : Leads & Contacts */
const exportBtn = (entity) => { const [from, to] = curRange(); return `<a class="btn sm" href="api.php?action=export&entity=${entity}${SC(entity).date ? "&from=" + from + "&to=" + to : ""}" title="Télécharger la liste de la période (CSV)">${ic("download", "sm")} Exporter</a>`; };

VIEWS["leads/contacts"] = { range: true, fn: async param => {
  const all = await rangeList("contacts");
  // Filtre de l'adresse : une catégorie de canaux (#leads/contacts/social) ou un canal précis (#leads/contacts/instagram).
  const isCat = param && (param === "other" || TX().categories.some(c => c.key === param));
  const sel = (id, label, opts, v = "") => `<select id="${id}" aria-label="${esc(label)}"><option value="">${esc(label)} : tous</option>${Object.entries(opts).map(([k, l]) => `<option value="${esc(k)}"${k === v ? " selected" : ""}>${esc(l)}</option>`).join("")}</select>`;
  const chOpts = `<option value="">Canal : tous</option>` + TX().categories.map(c => { const chs = channelsIn(c.key); return chs.length ? `<optgroup label="${esc(c.label)}">${chs.map(x => `<option value="${esc(x.key)}"${x.key === param ? " selected" : ""}>${esc(x.label)}</option>`).join("")}</optgroup>` : ""; }).join("");
  S.contactRows = isCat ? all.filter(inCat(param, "source")) : all;
  return catChips("leads/contacts", isCat ? param : (param ? "" : "all"), all, "source")
    + `<div class="toolbar">${addBtn("contacts", "Nouveau lead", null, "btn pri")}<button type="button" class="btn" data-import="contacts">${ic("upload", "sm")} Importer un fichier</button>
      <label class="field-search">${ic("search", "sm")}<input type="search" id="fQ" placeholder="Nom, téléphone, email…" aria-label="Rechercher un lead"></label>
      ${sel("fType", "Profil", EN("contact_types"))}<select id="fSource" aria-label="Canal">${chOpts}</select>${sel("fStatus", "Traitement", EN("contact_status"))}${sel("fCom", "Commercial", Object.assign({ "-": "Non assigné" }, EN("commercials")))}
      <span class="grow"></span>${exportBtn("contacts")}</div><div id="contactsOut"></div>`;
}, after: () => {
  const draw = () => {
    const q = ($("#fQ").value || "").toLowerCase().trim();
    const f = { type: $("#fType").value, source: $("#fSource").value, status: $("#fStatus").value, com: $("#fCom").value };
    const rows = S.contactRows.filter(c => (!f.type || c.type === f.type) && (!f.source || c.source === f.source) && (!f.status || c.status === f.status)
      && (!f.com || (f.com === "-" ? !c.assigned_to : c.assigned_to === f.com))
      && (!q || [c.name, c.phone, c.email, c.city, c.notes].join(" ").toLowerCase().includes(q)));
    const filtered = q || f.type || f.source || f.status || f.com;
    $("#contactsOut").innerHTML = card(`👥 Leads <span class="muted small">(${num(rows.length)} · ${esc(rangeLabel())})</span>`, table(rows, contactCols(), { entity: "contacts", emptyMsg: filtered ? "Aucun lead ne correspond à ces filtres. Retirez un filtre ou élargissez la période." : "Aucun lead sur la période. Ajoutez votre premier lead, ou importez votre fichier Excel/CSV existant." }));
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
    + card(`🔔 Leads sans commercial <span class="muted small">(${num(un.length)})</span>`, table(un, contactCols(), { entity: "contacts", emptyMsg: "Tous les leads de la période ont un commercial. Rien à assigner pour l'instant." }));
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
  return `<p class="muted" style="margin-top:0">Les canaux et supports de communication se gèrent dans <a href="#comm/taxonomy">Communication › Canaux & supports</a> ; la connexion à Facebook, Instagram et Meta Ads dans <a href="#connexions/meta">Connexions</a>.</p>
  <div class="set-grid">
    ${card("Vous & votre organisation", `<div class="form" style="grid-template-columns:1fr 1fr"><div class="fld full"><label for="sOwner">Votre prénom</label><input type="text" id="sOwner" maxlength="40" value="${esc(s.owner_name || "")}" placeholder="ex. Mohammed"><small>Pour vous saluer sur la page d'accueil.</small></div><div class="fld"><label for="sOrg">Nom de l'organisation</label><input type="text" id="sOrg" value="${esc(s.org)}"></div><div class="fld"><label for="sCur">Devise</label><input type="text" id="sCur" value="${esc(s.currency)}"></div>
      <div class="fld full"><label for="sCom">Commerciaux (un par ligne)</label><textarea id="sCom" rows="5">${esc(s.commercials.join("\n"))}</textarea><small>Proposés dans « Commercial responsable » des leads et des questions.</small></div></div>`)}
    ${card("Rapports automatiques par email", `<div class="form" style="grid-template-columns:1fr"><div class="fld"><label for="sRec">Destinataires (une adresse par ligne)</label><textarea id="sRec" rows="4">${esc(s.recipients.join("\n"))}</textarea></div>
      <div class="checks"><label><input type="checkbox" id="sD"${s.send_daily ? " checked" : ""}> Quotidien (chaque matin, la veille)</label><label><input type="checkbox" id="sW"${s.send_weekly ? " checked" : ""}> Hebdomadaire (le lundi)</label><label><input type="checkbox" id="sM"${s.send_monthly ? " checked" : ""}> Mensuel (le 1er)</label></div>
      <div class="fld"><label for="sUrl">Adresse du tableau de bord (lien dans les emails)</label><input type="url" id="sUrl" value="${esc(s.dashboard_url)}" placeholder="${esc(S.boot.url)}"></div>
      <div class="ctrl"><input type="email" id="sTest" placeholder="adresse pour un email de test"><button type="button" class="btn" id="sTestBtn">Envoyer un test</button></div>
      <small class="muted">Envoi : ${S.boot.mail.smtp ? "compte SMTP " + esc(S.boot.mail.from) : "fonction mail() du serveur (" + esc(S.boot.mail.from) + ")"}.</small></div>`)}
  </div>
  <div class="toolbar"><button type="button" class="btn pri" id="sSave">Enregistrer les réglages</button></div>
  ${card("Installer l'envoi automatique (une seule fois)", `<p style="margin-top:0">Dans <b>cPanel → Tâches Cron</b>, ajouter une tâche <b>une fois par jour</b> (par exemple Minute <code>5</code>, Heure <code>7</code>, autres champs <code>*</code>) avec la commande :</p><pre class="code">${esc(cronCmd)}</pre>
    <p class="small muted">Chaque matin : rapport de la veille ; le lundi : rapport de la semaine écoulée ; le 1er : rapport du mois écoulé. Les rapports sont archivés dans Overview → Archives, même sans destinataire.</p>`)}
  ${card("Exporter les données (CSV / Excel)", `<p class="muted" style="margin-top:0">Un fichier par section, lisible dans Excel ou Google Sheets.</p><div class="ctrl">${Object.keys(S.boot.schema).map(e => `<a class="btn sm" href="api.php?action=export&entity=${e}">${ic("download", "sm")} ${esc(S.boot.schema[e].label)}</a>`).join("")}</div>`)}`;
}, after: () => {
  $("#sSave").addEventListener("click", async () => {
    try {
      const d = await api("settings_save", {}, { owner_name: $("#sOwner").value, org: $("#sOrg").value, currency: $("#sCur").value, commercials: $("#sCom").value, recipients: $("#sRec").value, send_daily: $("#sD").checked, send_weekly: $("#sW").checked, send_monthly: $("#sM").checked, dashboard_url: $("#sUrl").value });
      S.boot.settings = d.settings; S.boot.enums = d.enums;
      paintUser();
      S.cache.clear();
      toast("Réglages enregistrés");
      route();
    } catch (e) { toast(e.message, "err"); }
  });
  $("#sTestBtn").addEventListener("click", async () => {
    try { const d = await api("test_mail", {}, { email: $("#sTest").value }); toast("Email de test envoyé à " + d.to); } catch (e) { toast(e.message, "err"); }
  });
} };

/* ================================================================ vues : Connexions (Meta) */
/** Petit repère « importé depuis Meta » sur une ligne synchronisée. */
const metaTag = r => r && r.ext_id && String(r.ext_id).startsWith("meta:") ? `<span class="sync" title="Importé automatiquement depuis Meta">${ic("link", "xs")}Meta</span>` : "";
const META_BACK = {
  connecte: ["Compte Facebook connecté. Choisissez maintenant la Page et les comptes publicitaires.", ""],
  annule: ["Connexion annulée : rien n'a été autorisé. Recommencez quand vous voulez.", "err"],
  expire: ["La demande de connexion a expiré. Relancez la connexion (bouton « Se connecter avec Facebook » ou « Se connecter avec Instagram »).", "err"],
  erreur: ["Facebook a refusé la connexion : le détail s'affiche à l'étape « Connexion au compte Facebook ».", "err"],
  "sans-app": ["Renseignez d'abord l'app Meta (étape 1).", "err"],
  "instagram-connecte": ["Compte Instagram connecté : ses chiffres seront importés à la prochaine synchronisation.", ""],
  "sans-app-instagram": ["Renseignez d'abord l'ID et la clé secrète de l'app Instagram (étape 3).", "err"],
  "instagram-erreur": ["Instagram a refusé la connexion : le détail s'affiche dans la carte Instagram (étape 3).", "err"],
};
const metaWhen = ts => ts ? new Date(ts * 1000).toLocaleString("fr-FR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "jamais";
const META_STEP_IC = { fb_stats: ["share", "blue"], ig_stats: ["share", "pink"], fb_posts: ["megaphone", "blue"], ig_posts: ["megaphone", "pink"], ads: ["target", "orange"], leads: ["user-plus", "green"] };
function metaResult(res) {
  if (!res) return empty("Aucune synchronisation pour l'instant. Lancez la première : elle importe les derniers jours d'un coup.", "", { icon: ["clock", "violet"] });
  return `<ul class="sync-steps">${res.steps.map(s => {
    const m = META_STEP_IC[s.key] || ["link", "violet"];
    const counts = s.counts.map(c => `${num(c.added)} ${esc(c.label)} ajouté(s)` + (c.updated ? `, ${num(c.updated)} mis à jour` : "") + (c.linked ? `, ${num(c.linked)} relié(s) à une saisie existante` : "")).join(" · ");
    return `<li class="${s.status}">${tile(m[0], m[1], "sm")}<span><b>${esc(s.label)}</b><small>${s.status === "skip" ? esc(s.message) : esc(counts || "Rien de nouveau")}${s.status !== "skip" && s.message ? `<br><span class="${s.status === "error" ? "bad" : ""}">${esc(s.message)}</span>` : ""}</small></span><span class="pill ${s.status === "ok" ? "good" : s.status === "error" ? "crit" : ""}">${s.status === "ok" ? "OK" : s.status === "error" ? "Erreur" : "Ignoré"}</span></li>`;
  }).join("")}</ul>`;
}

VIEWS["connexions/meta"] = { fn: async param => {
  if (META_BACK[param]) {
    const [msg, kind] = META_BACK[param];
    setTimeout(() => toast(msg, kind), 50);
    history.replaceState(null, "", "#connexions/meta");
  }
  const d = await api("meta_status");
  const m = S.meta = d.meta;
  S.boot.meta = m;
  const appOk = !!m.app_source, conn = m.fb_connected, chosen = !!(m.page || m.ad_accounts.length || m.instagram);
  const step = (n, done, cur, title, body, open = true) => `<section class="card mstep${done ? " done" : ""}${cur ? " cur" : ""}"><div class="card-h"><h2><span class="mnum">${done ? ic("check", "sm") : n}</span>${esc(title)}</h2></div>${open ? body : ""}</section>`;
  const appSrc = { ecom: "L'app Meta d'ecom.dehini.com est utilisée", config: "App Meta réglée dans config.php", dashboard: "App Meta enregistrée" }[m.app_source] || "";
  const appForm = `<div class="form"><div class="fld"><label for="mApp">Identifiant de l'app (App ID)</label><input type="text" id="mApp" inputmode="numeric" placeholder="ex. 1234567890123456" value="${esc(m.app_source === "dashboard" ? m.app_id : "")}"></div>
      <div class="fld"><label for="mSec">Clé secrète de l'app</label><input type="password" id="mSec" autocomplete="off" placeholder="${m.app_source === "dashboard" ? "Inchangée — tapez-en une nouvelle pour la remplacer" : "32 caractères"}"></div></div>
    <div class="toolbar" style="margin:10px 0 0"><button type="button" class="btn pri" id="mAppSave">Enregistrer l'app</button></div>`;
  const redir = id => `<span class="copy"><code id="${id}">${esc(m.redirect_uri)}</code><button type="button" class="icon-btn" data-copy="#${id}" aria-label="Copier l'adresse" title="Copier">${ic("copy", "sm")}</button></span>`;
  const howApp = `<ol class="howto"><li>Sur <b>developers.facebook.com</b> › Mes apps › <b>Créer une app</b> (type « Entreprise »), reliée au Business Manager de YINVESTI.</li>
    <li>Ajoutez le produit <b>Facebook Login</b>, puis dans ses paramètres l'adresse de retour : ${redir("mRedir")}</li>
    <li>Dans Paramètres › Général, copiez l'<b>App ID</b> et la <b>clé secrète</b> ci-dessous.</li></ol>`;
  // « URL bloquée » chez Facebook : l'adresse de retour n'est pas autorisée dans l'app.
  const allowBox = `<div class="allow">${tile("alert", "amber", "sm")}<div><b>Avant de cliquer : autorisez cette adresse dans l'app Meta</b>
      <p>Sinon Facebook affiche « URL bloquée ». Copiez-la exactement (https, sans www si vous ouvrez le tableau de bord sans www) :</p>${redir("mRedir2")}
      <ol class="howto"><li>Ouvrez ${m.app_url ? `<a href="${esc(m.app_url)}" target="_blank" rel="noopener">votre app Meta</a>` : "votre app sur developers.facebook.com"}${m.app_id ? ` (ID <code>${esc(m.app_id)}</code>)` : ""}.</li>
      <li>Menu de gauche : <b>Facebook Login</b> (ou <b>Facebook Login for Business</b>) › <b>Paramètres</b>.</li>
      <li>Activez <b>Connexion OAuth cliente</b> et <b>Connexion OAuth Web</b>, collez l'adresse dans <b>URI de redirection OAuth valides</b>, puis <b>Enregistrer les modifications</b>.</li>
      <li>Dans Paramètres › Général, ajoutez <code>dehini.com</code> aux <b>Domaines de l'app</b> (et la plateforme « Site web » avec <code>https://dehini.com/</code> si elle manque).</li></ol>
      <p class="small muted" style="margin:0">L'adresse ne s'affiche pas avec le bon domaine ? Réglez « Adresse du tableau de bord » dans <a href="#settings">Réglages</a> (ex. <code>https://dehini.com/marketing/</code>).</p></div></div>`;
  const s1 = appOk
    ? `<p class="ok-line">${ic("check-circle", "sm")} ${esc(appSrc)}${m.app_id ? ` · ID <code>${esc(m.app_id)}</code>` : ""}</p><details class="more"><summary>Utiliser une autre app Meta</summary>${howApp}${appForm}</details>`
    : `<p class="muted" style="margin-top:0">Une app Meta (gratuite) sert de « clé » au tableau de bord pour lire vos chiffres. À faire une seule fois.</p>${howApp}${appForm}`;
  const exp = m.token_kind === "manual" && !m.token_expires ? "n'expire pas" : m.days_left == null ? "" : m.days_left < 0 ? "a expiré" : `expire dans ${num(m.days_left)} jour(s)`;
  const s2 = conn
    ? `${m.token_invalid ? `<div class="banner warn">${ic("alert", "sm")} Meta refuse la connexion (expirée ou retirée) : reconnectez-vous pour reprendre la synchronisation.</div>` : ""}${m.oauth_error ? `<div class="banner warn">${ic("alert", "sm")}<span>Dernière tentative refusée : ${esc(m.oauth_error)}</span></div>` : ""}
       <div class="who-card">${tile("users", "blue")}<span><b>${esc(m.user_name || "Compte Meta")}</b><small>${m.token_kind === "manual" ? "Jeton d'utilisateur système" : "Connecté avec Facebook"}${exp ? " · " + exp : ""}</small></span>
       <span class="grow"></span>${appOk ? `<a class="btn sm" href="meta.php?start=1">${ic("link", "sm")} Reconnecter</a>` : ""}<button type="button" class="btn sm del" id="mOff">${ic("logout", "sm")} Déconnecter</button></div>
       ${m.days_left != null && m.days_left >= 0 && m.days_left <= 10 && m.token_kind !== "manual" ? `<p class="small" style="margin:10px 0 0">Les connexions Facebook durent environ 60 jours : cliquez sur « Reconnecter » avant l'échéance, ou utilisez un jeton d'utilisateur système qui n'expire pas.</p>` : ""}`
    : `${m.oauth_error ? `<div class="banner warn">${ic("alert", "sm")} ${esc(m.oauth_error)}</div>` : ""}
       <p class="muted" style="margin-top:0">Le tableau de bord ne fait que <b>lire</b> : il ne publie rien, n'envoie aucun message et ne dépense rien chez Meta.</p>
       ${appOk ? allowBox : ""}
       ${appOk ? `<div class="cfg"><div class="cfg-h"><label for="mCfg"><b>ID de configuration</b></label>${m.config_id ? '<span class="pill good">Enregistré</span>' : '<span class="pill warn">À renseigner si Facebook affiche « config_id est requis »</span>'}</div>
         <p class="small muted">Nécessaire quand l'app utilise <b>Facebook Login for Business</b> : les autorisations viennent alors d'une configuration créée dans l'app.</p>
         <div class="toolbar" style="margin:0"><input type="text" id="mCfg" inputmode="numeric" placeholder="ex. 1234567890123456" value="${esc(m.config_id || "")}" aria-label="ID de configuration"><button type="button" class="btn sm${m.config_id ? "" : " pri"}" id="mCfgSave">Enregistrer l'ID</button></div>
         <details class="more"${m.config_id ? "" : " open"}><summary>Créer la configuration (2 minutes)</summary><ol class="howto">
           <li>Dans ${m.app_url ? `<a href="${esc(m.app_url)}" target="_blank" rel="noopener">votre app Meta</a>` : "votre app Meta"} : <b>Facebook Login for Business</b> › <b>Configurations</b> › <b>Créer une configuration</b>.</li>
           <li>Nom : <code>Marketing Dashboard</code> · Type de jeton : <b>Jeton d'accès utilisateur</b> · Expiration : <b>Jamais</b> si le choix existe (sinon 60 jours).</li>
           <li>Actifs : <b>Pages</b>, <b>Comptes publicitaires</b>, <b>Comptes Instagram</b>.</li>
           <li>Autorisations : <span class="copy"><code id="mScopes">${esc(m.scopes.join(", "))}</code><button type="button" class="icon-btn" data-copy="#mScopes" aria-label="Copier la liste" title="Copier">${ic("copy", "sm")}</button></span><br><span class="small muted">Une autorisation absente de la liste ? Ajoutez d'abord le cas d'utilisation correspondant (Pages, Instagram, API Marketing, Lead Ads) dans « Cas d'utilisation ».</span></li>
           <li>Créez la configuration, copiez son <b>ID</b> et collez-le ci-dessus.</li></ol>
           <p class="small muted" style="margin:8px 0 0">Facebook affiche « Sorry, something went wrong » ? L'ID collé n'est sans doute pas celui de la configuration (ni l'ID de l'app, ni celui du Business Manager), ou la configuration a été supprimée : recopiez-le depuis Configurations.</p></details></div>` : ""}
       <a class="btn pri big${appOk ? "" : " disabled"}" ${appOk ? 'href="meta.php?start=1"' : 'aria-disabled="true"'}>${ic("link", "sm")} Se connecter avec Facebook</a>
       <details class="more"><summary>Autre méthode : coller un jeton d'utilisateur système</summary>
         <p class="small muted">Business Manager › Paramètres › Utilisateurs système › Générer un jeton (avec les autorisations ${m.scopes.slice(0, 4).join(", ")}…). Ce jeton n'expire pas : idéal pour la synchronisation automatique.</p>
         <textarea id="mTok" rows="3" placeholder="EAAB…" aria-label="Jeton d'accès"></textarea><div class="toolbar" style="margin:8px 0 0"><button type="button" class="btn" id="mTokSave">Vérifier et enregistrer le jeton</button></div></details>`;
  const s3 = `<div id="mAssets"><p class="loading">Chargement de vos Pages et comptes publicitaires…</p></div>`;
  const sync = `<section class="card" id="mSync"><div class="card-h"><h2>${tile("trend", "violet", "sm")}Synchronisation</h2><div class="ctrl">${chosen ? fullBtn("messages,comments") : ""}<button type="button" class="btn pri" id="mGo"${chosen ? "" : " disabled"}>${ic("trend", "sm")} Synchroniser maintenant</button></div></div>
      ${conn && !m.page ? `<div class="banner warn">${ic("alert", "sm")}<span>Aucune Page Facebook choisie : Facebook, les leads et les messages ne sont pas importés. Choisissez la Page à l'étape 3 ci-dessus, puis « Enregistrer ces choix ».</span></div>` : ""}
      ${m.running ? `<div class="banner">${ic("clock", "sm")}<span>Une synchronisation est en cours (autre onglet, synchronisation automatique ou tâche cron) : les nouvelles données arrivent à la fin. Rechargez la page dans une minute.</span></div>` : ""}
      ${m.last_result && m.last_result.more ? `<div class="banner warn">${ic("alert", "sm")}<span>La dernière synchronisation n'est pas terminée (beaucoup de données) : la suite se fait toute seule par lots, ou cliquez sur « Synchroniser maintenant ».</span></div>` : ""}
      <p class="muted" style="margin-top:0">Dernière : <b>${esc(metaWhen(m.last_sync))}</b>${m.last_result ? ` · du ${fdate(m.last_result.from, false)} au ${fdate(m.last_result.to)}` : ""}.</p>
      <div class="auto-sync"><label for="mAuto"><b>Synchronisation automatique</b><small>Facebook, Instagram, Meta Ads, leads, messages et commentaires, pendant que le tableau de bord est ouvert (même dans un onglet en arrière-plan).</small></label>
        <select id="mAuto">${[[15, "Toutes les 15 minutes"], [30, "Toutes les 30 minutes"], [60, "Toutes les heures"], [180, "Toutes les 3 heures"], [360, "Toutes les 6 heures"], [720, "Toutes les 12 heures"], [0, "Désactivée"]].map(([v, l]) => `<option value="${v}"${+m.auto_sync === v ? " selected" : ""}>${l}</option>`).join("")}</select></div>
      <div id="mRes">${metaResult(m.last_result)}</div></section>`;
  const what = [["share", "violet", "Social Media › Relevés", "Portée, vues, engagement et abonnés de la Page et du compte Instagram, jour par jour."],
    ["megaphone", "violet", "Communication › Actions", "Chaque publication, Reel, carrousel ou story, avec ses chiffres. Cible et objectif devinés d'après le texte."],
    ["target", "orange", "Ads Manager", "Campagnes, budgets (convertis en " + esc(m.currency) + "), dépenses, leads et conversions jour par jour."],
    ["user-plus", "blue", "Leads & Contacts", "Les leads des formulaires Lead Ads, avec leur campagne. Profil deviné ; une personne déjà saisie est reliée, pas dupliquée."],
    ["message", "teal", "Questions", "Les commentaires qui posent une question (français, arabe, darija), avec leur sujet."]];
  return `<div class="hero">${tile("link", "violet")}<div><h1>Facebook · Instagram · Meta Ads</h1><p>Reliez vos comptes une fois : les chiffres arrivent tout seuls dans chaque section, déjà classés. Ce que vous complétez (statut d'un lead, commercial, objectif chiffré…) n'est jamais écrasé.</p></div></div>
    <div class="dash"><div class="main-col">
      ${step(1, appOk, !appOk, "App Meta", s1)}
      ${step(2, conn && !m.token_invalid, appOk && (!conn || m.token_invalid), "Connexion au compte Facebook", s2)}
      ${step(3, chosen, conn && !chosen, "Page, Instagram et comptes publicitaires", conn ? s3 : `<p class="muted" style="margin:0">Disponible une fois le compte connecté.</p>`)}
      ${conn || m.instagram ? sync : ""}
    </div><div class="side-col">
      <section class="card"><div class="card-h"><h2>${tile("sparkles", "amber", "sm")}Ce qui arrive tout seul</h2></div><div class="alist">${what.map(w => `<div class="arow">${tile(w[0], w[1], "sm")}<span><b>${w[2]}</b><small>${w[3]}</small></span></div>`).join("")}</div>
        <p class="small muted" style="margin:10px 0 0">Le classement suit vos <a href="#connexions/rules">règles de classement</a>. Une donnée importée puis supprimée n'est pas réimportée.</p></section>
      <section class="card"><div class="card-h"><h2>${tile("clock", "teal", "sm")}Même tableau de bord fermé</h2></div><p class="small" style="margin-top:0">Pour que tout se synchronise aussi quand personne n'a ouvert le tableau de bord : dans <b>cPanel › Tâches Cron</b>, « Paramètres communs » : <b>Une fois par heure</b>, puis collez cette commande et cliquez sur « Ajouter une nouvelle tâche Cron » :</p>
        <pre class="code" id="mCron">/usr/local/bin/php ~/public_html/marketing/cron.php --meta-only &gt; /dev/null 2&gt;&amp;1</pre><button type="button" class="btn sm" data-copy="#mCron">${ic("copy", "sm")} Copier la commande</button>
        <p class="small muted" style="margin:10px 0 0">La tâche du matin (rapports) synchronise déjà une fois par jour ; celle-ci ajoute une synchronisation chaque heure.</p></section>
    </div></div>`;
}, after: async () => {
  const m = S.meta;
  const el = id => document.getElementById(id);
  if (el("mAppSave")) el("mAppSave").addEventListener("click", async e => {
    e.target.disabled = true;
    try { await api("meta_save", {}, { app_id: el("mApp").value, app_secret: el("mSec").value }); toast("App Meta enregistrée"); route(); }
    catch (err) { toast(err.message, "err"); e.target.disabled = false; }
  });
  if (el("mCfgSave")) el("mCfgSave").addEventListener("click", async e => {
    e.target.disabled = true;
    try { await api("meta_save", {}, { config_id: el("mCfg").value }); toast(el("mCfg").value.trim() ? "ID de configuration enregistré : vous pouvez vous connecter" : "ID de configuration retiré : les autorisations sont demandées directement"); route(); }
    catch (err) { toast(err.message, "err"); e.target.disabled = false; }
  });
  if (el("mTokSave")) el("mTokSave").addEventListener("click", async e => {
    e.target.disabled = true; e.target.textContent = "Vérification…";
    try { await api("meta_token", {}, { token: el("mTok").value }); toast("Jeton accepté : compte connecté"); route(); }
    catch (err) { toast(err.message, "err"); e.target.disabled = false; e.target.textContent = "Vérifier et enregistrer le jeton"; }
  });
  if (el("mOff")) el("mOff").addEventListener("click", async () => {
    if (!(await askDialog({ icon: ["logout", "pink"], title: "Déconnecter le compte Meta ?", text: "La synchronisation s'arrête. Les données déjà importées restent dans le tableau de bord.", ok: "Déconnecter", cancel: "Rester connecté", danger: true }))) return;
    try { await api("meta_disconnect", {}, {}); toast("Compte Meta déconnecté"); route(); } catch (err) { toast(err.message, "err"); }
  });
  if (el("mAuto")) el("mAuto").addEventListener("change", async e => {
    try { const d = await api("meta_save", {}, { auto_sync: +e.target.value }); S.boot.meta = d.meta; toast(+e.target.value ? "Synchronisation automatique : " + e.target.selectedOptions[0].textContent.toLowerCase() : "Synchronisation automatique désactivée"); }
    catch (err) { toast(err.message, "err"); }
  });
  if (el("mGo")) el("mGo").addEventListener("click", async e => {
    const b = e.target.closest("button");
    b.disabled = true;
    try {
      const r = await metaSyncLots({}, n => { b.innerHTML = `<span class="spin"></span> Synchronisation… ${n > 1 ? "lot " + n : "(jusqu'à une minute)"}`; });
      const t = r.tot;
      toast(r.ok ? `Synchronisé : ${num(t.added)} nouvelle(s) donnée(s), ${num(t.updated)} mise(s) à jour` : "Synchronisation terminée avec des erreurs : voir le détail", r.ok ? "" : "err");
      S.cache.clear();
      const rf = await api("refs"); S.boot.refs = rf.refs; S.boot.enums = rf.enums;
      route();
    } catch (err) { toast(err.message, "err"); b.disabled = false; b.innerHTML = `${ic("trend", "sm")} Synchroniser maintenant`; }
  });
  if (!el("mAssets")) return;
  let a;
  try { a = (await api("meta_assets")).assets; }
  catch (err) { el("mAssets").innerHTML = `<div class="banner warn">${ic("alert", "sm")} ${esc(err.message)}</div>`; return; }
  const curr = S.boot.settings.currency;
  const redir = id => `<span class="copy"><code id="${id}">${esc(m.redirect_uri)}</code><button type="button" class="icon-btn" data-copy="#${id}" aria-label="Copier l'adresse" title="Copier">${ic("copy", "sm")}</button></span>`;
  // Sélection en cours (enregistrée seulement au clic sur « Enregistrer ») et affichage : résumé, ou liste à choisir.
  const sel = { page: m.page ? m.page.id : (a.pages.length === 1 ? a.pages[0].id : ""), ads: new Set(m.ad_accounts.map(x => x.id)) };
  const ui = { editPage: !m.page, editAds: !m.ad_accounts.length, q: "", qp: "", off: false };
  const pageOf = id => a.pages.find(p => p.id === id) || null;
  const adOf = id => a.ad_accounts.find(x => x.id === id) || m.ad_accounts.find(x => x.id === id) || { id, name: "Compte " + id, currency: "", active: false, status: "Inaccessible" };
  const igDirect = m.instagram && m.instagram.via === "instagram";
  const sum = (icn, tint, title, sub, btn) => `<div class="pick-sum">${tile(icn, tint, "sm")}<span><b>${title}</b><small>${sub}</small></span><span class="grow"></span>${btn}</div>`;
  const search = (id, ph, v) => `<label class="field-search">${ic("search", "sm")}<input type="search" id="${id}" placeholder="${esc(ph)}" value="${esc(v)}" aria-label="${esc(ph)}"></label>`;
  const drawPage = () => {
    if (!a.pages.length) return `<p class="muted">Aucune Page Facebook accessible avec ce compte. Vérifiez vos accès à la Page dans Meta Business Suite, ou choisissez la Page dans la fenêtre Facebook en vous reconnectant.</p>`;
    const p = pageOf(sel.page);
    if (!ui.editPage && p) return sum("share", "blue", esc(p.name), p.instagram ? "Instagram relié : @" + esc(p.instagram.username) : "Pas de compte Instagram relié à cette Page", `<button type="button" class="btn sm" data-mx="editPage">Changer</button>`);
    const q = foldTxt(ui.qp);
    const list = a.pages.filter(x => !q || foldTxt(x.name).includes(q));
    return (a.pages.length > 4 ? `<div class="toolbar" style="margin:0 0 10px">${search("mQp", "Rechercher une Page…", ui.qp)}</div>` : "")
      + `<div class="choices">${list.map(x => `<label class="choice${sel.page === x.id ? " on" : ""}"><input type="radio" name="mPage" value="${esc(x.id)}"${sel.page === x.id ? " checked" : ""} hidden><span class="ic">${tile("share", "blue")}</span><span class="cl">${esc(x.name)}</span><small>${x.instagram ? "+ Instagram @" + esc(x.instagram.username) : "Sans Instagram relié"}</small></label>`).join("")}</div>`
      + (list.length ? "" : `<p class="muted small">Aucune Page ne correspond à « ${esc(ui.qp)} ».</p>`);
  };
  const drawIg = () => {
    const p = pageOf(sel.page);
    if (igDirect) return sum("share", "pink", m.instagram.username ? "@" + esc(m.instagram.username) : "Compte Instagram " + esc(m.instagram.id), "Connecté avec Instagram" + (m.instagram.days_left != null ? ` · renouvelé tout seul (encore ${num(Math.max(0, m.instagram.days_left))} j)` : ""), `<a class="btn sm" href="meta.php?start=instagram">Reconnecter</a><button type="button" class="btn sm del" id="mIgOff">Déconnecter</button>`)
      + (m.ig_diag ? `<div class="banner warn" style="margin:10px 0 0">${ic("alert", "sm")}<span>Connecté, mais Instagram refuse de lire le compte par « me » : la synchronisation utilisera son identifiant. Si elle échoue aussi, envoyez ce diagnostic : <b>${esc(m.ig_diag)}</b></span></div>` : "");
    if (p && p.instagram) return sum("share", "pink", "@" + esc(p.instagram.username), "Relié à la Page " + esc(p.name) + " : importé avec elle", "");
    const igIsFb = !!m.ig_app_id && m.ig_app_id === m.app_id; // ID Facebook collé à la place de l'ID Instagram
    const ready = m.ig_app_ready && !igIsFb;
    return `<div class="ig-box">${m.ig_oauth_error ? `<div class="banner warn">${ic("alert", "sm")}<span>${esc(m.ig_oauth_error)}</span></div>` : ""}<p style="margin:0 0 10px">${p ? `Aucun compte Instagram professionnel n'est relié à la Page <b>${esc(p.name)}</b>.` : "Choisissez d'abord une Page, ou connectez Instagram directement."} Deux solutions :</p>
      <div class="ig-opts"><div class="ig-opt"><b>1. Connecter Instagram directement</b><small>Sans passer par la Page. Le compte doit être un compte <b>professionnel</b> (Entreprise ou Créateur).</small>
        ${ready ? `<a class="btn pri" href="meta.php?start=instagram">${ic("share", "sm")} Se connecter avec Instagram</a>` : ""}
        ${igIsFb ? `<div class="banner warn" style="margin:6px 0 0">${ic("alert", "sm")}<span>L'ID enregistré est l'App ID <b>Facebook</b> : Instagram répond alors « Invalid platform app ». Collez ci-dessous l'ID de l'app <b>Instagram</b>.</span></div>` : ""}
        <details class="more"${ready ? "" : " open"}><summary>${ready ? "Modifier l'app Instagram" : "Régler l'app Instagram (une seule fois)"}</summary>
          ${ready ? `<p class="small" style="margin:6px 0">Instagram affiche « <b>Invalid platform app</b> » ? L'ID enregistré (<code>${esc(m.ig_app_id)}</code>) n'est pas celui de l'app Instagram, ou la connexion Instagram n'est pas encore configurée dans l'app : suivez les étapes ci-dessous et recollez l'ID.</p>` : ""}
          <ol class="howto">
            <li>Dans votre app Meta : <b>Cas d'utilisation</b> › <b>Gérer les messages et le contenu sur Instagram</b> (ajoutez-le s'il manque) › <b>Personnaliser</b> › <b>Configuration de l'API avec la connexion Instagram</b>.</li>
            <li>Étape « Configurer la connexion entreprise d'Instagram » : collez l'URI de redirection ${redir("mRedir3")} puis enregistrez.</li>
            <li>En haut de cette page, copiez l'<b>ID de l'app Instagram</b> et la <b>clé secrète de l'app Instagram</b>. Ce ne sont <b>pas</b> l'App ID et la clé de la page Paramètres › Général.</li></ol>
          <div class="form"><div class="fld"><label for="mIgApp">ID de l'app Instagram</label><input type="text" id="mIgApp" inputmode="numeric" value="${esc(m.ig_app_id || "")}"></div><div class="fld"><label for="mIgSec">Clé secrète de l'app Instagram</label><input type="password" id="mIgSec" autocomplete="off" placeholder="${m.ig_app_id ? "Inchangée — tapez-en une nouvelle pour la remplacer" : "32 caractères"}"></div></div>
          <div class="toolbar" style="margin:10px 0 0"><button type="button" class="btn${ready ? "" : " pri"}" id="mIgSave">${ic("share", "sm")} Enregistrer et se connecter avec Instagram</button></div></details></div>
        <div class="ig-opt"><b>2. Relier Instagram à la Page</b><small>Meta Business Suite › Paramètres › Comptes › <b>Comptes Instagram</b> › Connecter, puis reconnectez Facebook ici : le compte apparaît avec la Page.</small></div></div></div>`;
  };
  const drawAds = () => {
    if (!a.ad_accounts.length && !m.ad_accounts.length) return `<p class="muted">Aucun compte publicitaire accessible (autorisation « ads_read » non accordée, ou aucun compte sur ce profil).</p>`;
    if (!ui.editAds && sel.ads.size) return `<div class="pick-sum multi">${tile("target", "orange", "sm")}<span><b>${num(sel.ads.size)} compte(s) publicitaire(s)</b><span class="chips" style="margin:6px 0 0">${[...sel.ads].map(id => { const x = adOf(id); return `<span class="chip static">${esc(x.name)} · ${esc(x.currency)}${x.active ? "" : ` · <span class="muted">${esc(x.status)}</span>`}</span>`; }).join("")}</span></span><span class="grow"></span><button type="button" class="btn sm" data-mx="editAds">Modifier</button></div>`;
    const q = foldTxt(ui.q);
    const inactive = a.ad_accounts.filter(x => !x.active && !sel.ads.has(x.id)).length;
    const list = a.ad_accounts.filter(x => (!q || foldTxt(x.name + " " + x.id).includes(q)) && (ui.off || x.active || sel.ads.has(x.id)))
      .sort((x, y) => (sel.ads.has(y.id) ? 1 : 0) - (sel.ads.has(x.id) ? 1 : 0));
    return `<div class="toolbar" style="margin:0 0 10px">${search("mQa", "Rechercher un compte (ex. yinvesti)…", ui.q)}${inactive ? `<label class="check small"><input type="checkbox" id="mOffT"${ui.off ? " checked" : ""}> Afficher les comptes inactifs (${num(inactive)})</label>` : ""}<span class="grow"></span><span class="muted small">${num(sel.ads.size)} sélectionné(s)</span></div>
      <div class="choices">${list.map(x => `<label class="choice${sel.ads.has(x.id) ? " on" : ""}"><input type="checkbox" name="mAd" value="${esc(x.id)}"${sel.ads.has(x.id) ? " checked" : ""} hidden><span class="ic">${tile("target", "orange")}</span><span class="cl">${esc(x.name)}</span><small>${esc(x.currency)} · ${x.active ? "Actif" : esc(x.status)}</small></label>`).join("")}</div>`
      + (list.length ? "" : `<p class="muted small">Aucun compte ne correspond${ui.q ? ` à « ${esc(ui.q)} »` : ""}.${inactive && !ui.off ? " Cochez « Afficher les comptes inactifs » pour voir les autres." : ""}</p>`)
      + `<p class="small muted" style="margin:8px 0 0">Les comptes « Paiement en attente » ou « Désactivé » gardent leur historique : vous pouvez les choisir pour importer leurs anciennes campagnes.</p>`;
  };
  const fxNeeded = () => [...sel.ads].map(id => adOf(id).currency).filter(c => c && c !== curr);
  el("mAssets").innerHTML = `<h3>Page Facebook</h3><div id="mPg"></div>
    <h3>Instagram</h3><div id="mIg"></div>
    <h3>Comptes publicitaires</h3><div id="mAds"></div>
    <div class="form" style="margin-top:14px"><div class="fld" id="mFxBox"><label for="mFx">Taux de conversion</label><div class="fx"><span>1 <b id="mFxCur">USD</b> =</span><input type="number" id="mFx" min="0" step="any" value="${esc(m.fx)}"><span>${esc(curr)}</span></div><small>Les montants Meta (budgets, dépenses) sont convertis en ${esc(curr)}.</small></div>
      <div class="fld"><label for="mBack">Première importation</label><select id="mBack">${[30, 60, 90].map(n => `<option value="${n}"${+m.backfill === n ? " selected" : ""}>${n} derniers jours</option>`).join("")}</select></div>
      <div class="fld"><label for="mProf">Profil d'un lead impossible à classer</label><select id="mProf">${Object.entries(EN("contact_types")).map(([k, l]) => `<option value="${k}"${m.default_profile === k ? " selected" : ""}>${esc(l)}</option>`).join("")}</select></div>
      <div class="fld full"><div class="checks"><label><input type="checkbox" id="mQ"${m.questions ? " checked" : ""}> Créer une question à traiter pour chaque commentaire qui pose une question (Leads & Contacts › Questions ; tous les commentaires restent dans « Commentaires »)</label><label><input type="checkbox" id="mM"${m.messages ? " checked" : ""}> Importer et analyser les messages Messenger et Instagram (dans Leads & Contacts › Messages)</label></div></div></div>
    <div class="toolbar" style="margin:14px 0 0"><button type="button" class="btn pri" id="mSave">Enregistrer ces choix</button></div>`;
  const draw = which => {
    if (!which || which === "page") el("mPg").innerHTML = drawPage();
    if (!which || which === "page" || which === "ig") el("mIg").innerHTML = drawIg();
    if (!which || which === "ads") el("mAds").innerHTML = drawAds();
    const cs = fxNeeded();
    el("mFxBox").hidden = !cs.length;
    if (cs.length) el("mFxCur").textContent = cs[0];
  };
  draw();
  const box = el("mAssets");
  box.addEventListener("change", e => {
    if (e.target.name === "mPage") { sel.page = e.target.value; ui.editPage = false; draw("page"); }
    if (e.target.name === "mAd") { if (e.target.checked) sel.ads.add(e.target.value); else sel.ads.delete(e.target.value); e.target.closest(".choice").classList.toggle("on", e.target.checked); const cs = fxNeeded(); el("mFxBox").hidden = !cs.length; if (cs.length) el("mFxCur").textContent = cs[0]; box.querySelector("#mAds .toolbar .muted.small").textContent = num(sel.ads.size) + " sélectionné(s)"; }
    if (e.target.id === "mOffT") { ui.off = e.target.checked; draw("ads"); }
  });
  box.addEventListener("input", e => {
    if (e.target.id === "mQa" || e.target.id === "mQp") {
      const k = e.target.id === "mQa" ? "q" : "qp", pos = e.target.selectionStart;
      ui[k] = e.target.value;
      draw(k === "q" ? "ads" : "page");
      const inp = el(e.target.id); inp.focus(); try { inp.setSelectionRange(pos, pos); } catch (err) {}
    }
  });
  box.addEventListener("click", async e => {
    const mx = e.target.closest("[data-mx]");
    if (mx) { ui[mx.dataset.mx] = true; draw(mx.dataset.mx === "editPage" ? "page" : "ads"); return; }
    if (e.target.closest("#mIgSave")) {
      const b = e.target.closest("button"); b.disabled = true;
      try { await api("meta_save", {}, { ig_app_id: el("mIgApp").value, ig_app_secret: el("mIgSec").value }); location.href = "meta.php?start=instagram"; }
      catch (err) { toast(err.message, "err"); b.disabled = false; }
      return;
    }
    if (e.target.closest("#mIgOff")) {
      if (!(await askDialog({ icon: ["logout", "pink"], title: "Déconnecter Instagram ?", text: "Les chiffres d'Instagram ne seront plus importés (sauf si un compte Instagram est relié à la Page). Les données déjà importées restent.", ok: "Déconnecter Instagram", cancel: "Garder la connexion", danger: true }))) return;
      try { await api("meta_save", {}, { ig_disconnect: true }); toast("Instagram déconnecté"); route(); } catch (err) { toast(err.message, "err"); }
    }
  });
  el("mSave").addEventListener("click", async e => {
    const b = e.target; b.disabled = true;
    try {
      await api("meta_save", {}, { page_id: sel.page, ad_accounts: [...sel.ads], fx: el("mFxBox").hidden ? 1 : el("mFx").value, backfill: +el("mBack").value, default_profile: el("mProf").value, questions: el("mQ").checked, messages: el("mM").checked });
      toast(m.last_sync ? "Choix enregistrés" : "Choix enregistrés : lancez la première synchronisation");
      route();
    } catch (err) { toast(err.message, "err"); b.disabled = false; }
  });
} };

/* ---------- règles de classement automatique */
const RULE_GROUPS = [
  ["audience", "Cible (publications, campagnes) et profil (leads, questions)", { investisseurs: "Investisseurs", porteurs: "Porteurs de projets" }, "users"],
  ["objective", "Objectif d'une publication (sinon : Notoriété)", null, "target"],
  ["question", "Sujet d'une question (sinon : Autre)", null, "message"],
];
const foldTxt = s => String(s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/’/g, "'").replace(/\s+/g, " ");
function classifyJs(text, groups) {
  const t = foldTxt(text);
  let best = "", score = 0;
  for (const [cat, words] of Object.entries(groups)) {
    const n = words.filter(w => (w = foldTxt(w)) && t.includes(w)).length;
    if (n > score) { best = cat; score = n; }
  }
  return best;
}
const isQuestionJs = t => { t = String(t || "").trim(); if (t.length < 4 || /^(@\S+\s*)+$/u.test(t)) return false; if (/[?؟]/.test(t)) return true; const f = " " + foldTxt(t) + " "; return [" combien", " comment ", " pourquoi", " quel ", " quelle", " quels ", " est-ce", "c'est quoi", " quand ", " info", " svp", " prix", " tarif", "شحال", "كيفاش", "واش", "بكم", "علاش", "وين", "وقتاش", "كيف", " هل ", "معلومات"].some(w => f.includes(w)); };

VIEWS["connexions/rules"] = { fn: async () => {
  const d = await api("meta_status");
  S.meta = d.meta;
  const R = d.meta.rules;
  const lbl = (g, c) => g === "audience" ? { investisseurs: "Investisseurs", porteurs: "Porteurs de projets" }[c] : g === "objective" ? L("ad_objectives", c) : L("inq_categories", c);
  return `<p class="muted" style="margin-top:0">Le tableau de bord lit le texte de chaque publication, campagne, formulaire ou commentaire importé et choisit la catégorie dont les mots-clés apparaissent le plus. Les accents et majuscules ne comptent pas ; un mot-clé peut être un début de mot (« inscri » trouve inscription, inscrivez-vous…). Les nouvelles règles s'appliquent aux prochaines données importées.</p>
    <div class="dash"><div class="main-col">${RULE_GROUPS.map(([g, title, , icn]) => `<section class="card"><div class="card-h"><h2>${tile(icn, "violet", "sm")}${esc(title)}</h2></div><div class="form rules">${Object.keys(R[g]).map(c => `<div class="fld"><label for="r_${g}_${c}">${esc(lbl(g, c))}</label><textarea id="r_${g}_${c}" data-rg="${g}" data-rc="${c}" rows="3">${esc(R[g][c].join(", "))}</textarea></div>`).join("")}</div></section>`).join("")}
      <div class="toolbar"><button type="button" class="btn pri" id="rSave">Enregistrer les règles</button><button type="button" class="btn ghost" id="rReset">Rétablir les règles par défaut</button></div>
    </div><div class="side-col"><section class="card" id="rTest"><div class="card-h"><h2>${tile("sparkles", "amber", "sm")}Essayer</h2></div>
      <p class="small muted" style="margin-top:0">Collez une légende, un nom de campagne ou un commentaire : le classement s'affiche avec les règles ci-contre, même non enregistrées.</p>
      <textarea id="rIn" rows="4" placeholder="ex. C'est quoi le ticket minimum pour investir ?"></textarea><div id="rOut" class="rv-list"></div></section></div></div>`;
}, after: () => {
  const read = () => {
    const out = {};
    for (const t of $$("[data-rg]", V)) { (out[t.dataset.rg] = out[t.dataset.rg] || {})[t.dataset.rc] = t.value.split(/[\n,;]+/).map(s => s.trim()).filter(Boolean); }
    return out;
  };
  const test = () => {
    const txt = $("#rIn").value, R = read();
    if (!txt.trim()) { $("#rOut").innerHTML = ""; return; }
    const aud = (() => { const i = classifyJs(txt, { x: R.audience.investisseurs }), p = classifyJs(txt, { x: R.audience.porteurs }); return i && p ? "tous" : i ? "investisseurs" : p ? "porteurs" : ""; })();
    const q = isQuestionJs(txt);
    const row = (l, v) => `<div class="rv"><span class="rv-l">${l}</span><span class="rv-v">${v}</span></div>`;
    $("#rOut").innerHTML = row("Cible", aud ? esc(L("audiences", aud)) : '<span class="muted">aucune</span>')
      + row("Objectif", esc(L("ad_objectives", classifyJs(txt, R.objective) || "notoriete")))
      + row("Question ?", q ? '<span class="pill good">Oui</span>' : '<span class="pill">Non</span>')
      + (q ? row("Sujet", esc(L("inq_categories", classifyJs(txt, R.question) || "autre"))) : "");
  };
  $("#rIn").addEventListener("input", test);
  $(".main-col", V).addEventListener("input", e => { if (e.target.dataset.rg) test(); });
  $("#rSave").addEventListener("click", async e => {
    e.target.disabled = true;
    try { await api("meta_save", {}, { rules: read() }); toast("Règles enregistrées : elles servent dès la prochaine synchronisation"); }
    catch (err) { toast(err.message, "err"); }
    e.target.disabled = false;
  });
  $("#rReset").addEventListener("click", async () => {
    if (!(await askDialog({ icon: ["alert", "amber"], title: "Rétablir les règles par défaut ?", text: "Vos mots-clés seront remplacés par la liste de départ.", ok: "Rétablir", cancel: "Garder mes règles" }))) return;
    try { await api("meta_save", {}, { rules: S.meta.rules_default }); toast("Règles par défaut rétablies"); route(); } catch (err) { toast(err.message, "err"); }
  });
} };

VIEWS["connexions/history"] = { fn: async () => {
  const d = await api("meta_status");
  if (!d.log.length) return empty("Aucune synchronisation pour l'instant. Elles s'afficheront ici avec ce qui a été importé à chaque fois.", `<a class="btn pri" href="#connexions/meta">${ic("link", "sm")} Relier Meta</a>`, { icon: ["clock", "violet"] });
  return card("🔗 Synchronisations Meta", `<div class="hist">${d.log.map(x => `<details class="hist-row"><summary><span class="pill ${x.ok ? "good" : "crit"}">${x.ok ? "OK" : "Erreur"}</span><b>${esc(metaWhen(x.at))}</b><span class="muted small">du ${fdate(x.from, false)} au ${fdate(x.to)}</span><span class="grow"></span><span class="small">${num(x.added)} ajout(s) · ${num(x.updated)} mise(s) à jour</span></summary>${metaResult(x.result)}</details>`).join("")}</div>`);
} };

/* ================================================================ assistant pas à pas (wizard + stepper) */
/**
 * Stepper : numéro de chaque étape (✓ une fois faite), son nom et, pour les étapes déjà
 * remplies, le choix retenu. On peut revenir à toute étape déjà atteinte.
 */
function stepperHtml(steps, at, reached, st) {
  return `<ol class="stepper">${steps.map((s, i) => {
    const state = i < at ? "done" : i === at ? "current" : i <= reached ? "seen" : "todo";
    const sum = i !== at && i <= reached && s.summary ? s.summary(st) : "";
    return `<li class="${state}"${i === at ? ' aria-current="step"' : ""}><button type="button" data-step="${i}"${i <= reached && i !== at ? "" : " disabled"}>`
      + `<span class="dot">${i !== at && i <= reached ? "✓" : i + 1}</span><span class="txt"><b>${esc(s.label)}</b>${sum ? `<small>${esc(sum)}</small>` : ""}</span></button></li>`;
  }).join("")}</ol>
  <div class="stepper-mini"><span>Étape ${at + 1} sur ${steps.length} · <b>${esc(steps[at].label)}</b></span><span class="bar"><i style="width:${Math.round((at + 1) / steps.length * 100)}%"></i></span></div>`;
}
/**
 * Assistant dans la fenêtre : une étape à la fois (divulgation progressive), validation à
 * chaque passage, retour possible. Chaque étape : label, question, summary(st), render(st),
 * collect(root, st), onClick(e, st) → true pour redessiner, onInput(e, st, root), validate(st).
 */
function openWizard(cfg) {
  const st = cfg.state, steps = cfg.steps, last = steps.length - 1;
  let at = cfg.start || 0, reached = Math.max(at, cfg.reached || 0);
  const root = () => $("#wiz");
  const collect = () => { if (steps[at].collect && root()) steps[at].collect(root(), st); };
  const err = m => { $("#wizErr").textContent = m || ""; };
  const draw = () => {
    const step = steps[at];
    openModal(`<div class="wiz" id="wiz"><div class="wiz-h"><h2>${esc(cfg.title)}</h2>${stepperHtml(steps, at, reached, st)}</div>
      <div class="wiz-body">${step.question ? `<p class="wiz-q">${esc(step.question)}</p>` : ""}<div id="wizStep">${step.render(st)}</div></div>
      <p class="form-err" id="wizErr"></p>
      <div class="wiz-foot">${cfg.foot ? cfg.foot(st) : ""}<span class="grow"></span><button type="button" class="btn ghost" data-close>Annuler</button>
        ${at > 0 ? `<button type="button" class="btn" data-wiz="prev">${ic("chev-l", "sm")} Précédent</button>` : ""}
        ${at < last ? `<button type="button" class="btn pri" data-wiz="next">Suivant ${ic("chev-r", "sm")}</button>` : ""}
        ${at === last && cfg.again ? `<button type="button" class="btn" data-wiz="again">${esc(cfg.again)}</button>` : ""}
        ${at === last || reached === last ? `<button type="button" class="btn${at === last ? " pri" : ""}" data-wiz="finish">${esc(cfg.finish || "Enregistrer")}</button>` : ""}</div></div>`);
    const r = root();
    r.addEventListener("click", onClick);
    r.addEventListener("input", e => { if (steps[at].onInput) steps[at].onInput(e, st, r); });
    r.addEventListener("change", e => { if (steps[at].onChange && steps[at].onChange(e, st)) refresh(); });
    r.addEventListener("keydown", e => { if (e.key === "Enter" && e.target.tagName === "INPUT" && !["checkbox", "radio"].includes(e.target.type)) { e.preventDefault(); at < last ? go(at + 1) : finish(false); } });
    const first = $("#wizStep input:not([type=checkbox]), #wizStep textarea", r);
    if (first) first.focus(); else { const b = $("#wizStep .choice.on, #wizStep .choice", r); if (b) b.focus(); }
  };
  const refresh = () => {
    collect();
    $("#wizStep").innerHTML = steps[at].render(st);
    $(".wiz-h", root()).innerHTML = `<h2>${esc(cfg.title)}</h2>${stepperHtml(steps, at, reached, st)}`;
    err("");
  };
  const go = to => {
    if (to < 0 || to > last || to === at) return;
    collect();
    for (let i = at; i < to; i++) {
      const m = steps[i].validate ? steps[i].validate(st) : "";
      if (m) { if (i !== at) { at = i; draw(); } err(m); return; }
    }
    at = to;
    reached = Math.max(reached, at);
    draw();
  };
  const finish = async again => {
    collect();
    for (let i = 0; i <= last; i++) {
      const m = steps[i].validate ? steps[i].validate(st) : "";
      if (m) { at = i; reached = Math.max(reached, i); draw(); err(m); return; }
    }
    try { await cfg.onFinish(st, again); } catch (e) { err(e.message); }
  };
  function onClick(e) {
    const sp = e.target.closest("[data-step]"), w = e.target.closest("[data-wiz]");
    if (sp) return sp.disabled ? null : go(+sp.dataset.step);
    if (w) {
      const a = w.dataset.wiz;
      if (a === "next") go(at + 1);
      else if (a === "prev") go(at - 1);
      else if (a === "finish") finish(false);
      else if (a === "again") finish(true);
      else if (a.startsWith("goto:")) go(+a.slice(5));
      return;
    }
    if (steps[at].onClick && steps[at].onClick(e, st)) refresh();
  }
  draw();
}
/** Carte de choix (bouton radio visuel). */
const choice = (attr, key, label, { icon = "", sub = "", on = false } = {}) => `<button type="button" class="choice${on ? " on" : ""}" ${attr}="${esc(key)}" aria-pressed="${on}">${icon ? `<span class="ic">${icon}</span>` : ""}<span class="cl">${esc(label)}</span>${sub ? `<small>${esc(sub)}</small>` : ""}</button>`;
/** Supports regroupés par type, dans l'ordre des types. */
const supportGroups = keys => TX().support_types.map(t => [t, keys.filter(k => (supportOf(k) || {}).type === t.key)]).filter(g => g[1].length);

/* ---------- action de communication : Catégorie → Canal → Support → Contenu → Cible → Objectif → KPI */
const OBJ_ICONS = { notoriete: ["megaphone", "violet"], trafic: ["link", "blue"], engagement: ["heart", "pink"], leads: ["target", "orange"], inscriptions: ["user-plus", "green"], conversions: ["coins", "green"], messages: ["message", "teal"] };
const objTile = (k, cls = "") => { const m = OBJ_ICONS[k] || ["target", "orange"]; return tile(m[0], m[1], cls); };
const OBJ_KPI = { notoriete: ["reach", "impressions"], trafic: ["clicks", "impressions"], engagement: ["engagement", "reach"], leads: ["leads", "cost"], inscriptions: ["conversions", "clicks"], conversions: ["conversions", "cost"], messages: ["leads", "engagement"] };
const KPI_L = { reach: "Portée", impressions: "Impressions", engagement: "Engagement", clicks: "Clics", leads: "Leads", conversions: "Conversions", cost: "Coût" };
const KPI_KEYS = Object.keys(KPI_L);
const kpiFmt = (k, v) => k === "cost" ? money(v) : num(v);
const ratesHtml = s => {
  const n = k => +s[k] || 0;
  const items = [["Taux d'engagement", ratio(n("engagement"), n("reach")), "pct"], ["CTR (clics / impressions)", ratio(n("clicks"), n("impressions")), "pct"], ["Coût par lead", ratio(n("cost"), n("leads")), "money"], ["Coût par conversion", ratio(n("cost"), n("conversions")), "money"]].filter(i => i[1] != null);
  return items.map(([l, v, f]) => `<span class="rate"><small>${esc(l)}</small><b>${f === "pct" ? pct(v, 1) : money(v)}</b></span>`).join("");
};
function actionReview(s) {
  const line = (label, val, step) => `<div class="rv"><span class="rv-l">${label}</span><span class="rv-v">${val || '<span class="muted">—</span>'}</span>${step != null ? `<button type="button" class="linkbtn" data-wiz="goto:${step}">Modifier</button>` : ""}</div>`;
  const cat = catOf(s.platform);
  const t = typeInfo((supportOf(s.format) || {}).type);
  const kpis = KPI_KEYS.filter(k => +s[k] > 0).map(k => `${KPI_L[k]} <b>${kpiFmt(k, s[k])}</b>`).join(" · ");
  return `<div class="review">${line("Catégorie", cat ? catIc(cat) + " " + esc(catInfo(cat).label) : "", 0)}${line("Canal", esc(L("sources", s.platform)), 0)}`
    + `${line("Support", s.format ? esc(L("supports", s.format)) + (t.label ? ` <span class="muted small">· ${esc(t.label)}</span>` : "") : "", 1)}`
    + `${line("Contenu", esc(s.topic), 2)}${line("Date", fdate(s.date), 2)}${line("Cible", esc(L("audiences", s.audience)), 2)}`
    + `${line("Objectif", s.objective ? esc((OBJ_ICONS[s.objective] || "") + " " + L("ad_objectives", s.objective)) : "", 2)}${line("KPI", kpis, 3)}</div>`
    + `<div class="rates">${ratesHtml(s)}</div>`;
}
function openActionWizard(row = null, preset = {}) {
  if (!CHANNELS().length) return toast("Ajoutez d'abord des canaux (Communication → Canaux & supports).");
  const st = Object.assign(defaults("posts"), row || {}, preset || {});
  st.category = (preset && preset.category) || catOf(st.platform) || "";
  if (!row) for (const k of KPI_KEYS) if (!+st[k]) st[k] = "";
  const steps = [
    { label: "Canal", question: "Par quel canal allons-nous toucher notre cible ?",
      summary: s => s.platform ? L("sources", s.platform) : "",
      render: s => {
        const cats = TX().categories.map(c => choice("data-cat", c.key, c.label, { icon: catTile(c.key), sub: channelsIn(c.key).length + " canal(aux)", on: s.category === c.key })).join("");
        let chs = "";
        if (s.category) {
          const list = channelsIn(s.category), c = catInfo(s.category);
          chs = `<h3 class="ih">${catIc(c.key)} ${esc(c.label)} · choisissez le canal</h3>` + (list.length
            ? `<div class="choices">${list.map(x => choice("data-ch", x.key, x.label, { sub: x.supports.length + " support(s)", on: s.platform === x.key })).join("")}</div>`
            : `<p class="muted">Aucun canal dans cette catégorie : ajoutez-en dans <a href="#comm/taxonomy" data-close>Canaux & supports</a>.</p>`);
        }
        return `<h3>Catégorie</h3><div class="choices cats">${cats}</div>${chs}`;
      },
      onClick: (e, s) => {
        const c = e.target.closest("[data-cat]"), x = e.target.closest("[data-ch]");
        if (c) { s.category = c.dataset.cat; if (catOf(s.platform) !== s.category) s.platform = ""; return true; }
        if (x) { s.platform = x.dataset.ch; if (s.format && !supportsOfChannel(s.platform).includes(s.format)) s.format = ""; return true; }
      },
      validate: s => s.platform ? "" : "Choisissez une catégorie, puis un canal." },
    { label: "Support", question: "Sous quelle forme allons-nous communiquer ?",
      summary: s => s.format ? L("supports", s.format) : "",
      render: s => {
        const own = supportsOfChannel(s.platform);
        const others = SUPPORTS().map(x => x.key).filter(k => !own.includes(k));
        const block = keys => supportGroups(keys).map(([t, ks]) => `<div class="sgrp"><span class="sgrp-l">${esc(t.label)}</span><div class="choices sm">${ks.map(k => choice("data-sup", k, L("supports", k), { on: s.format === k })).join("")}</div></div>`).join("");
        const all = s._allSup || !own.length || (s.format && !own.includes(s.format));
        const rest = ["digital", "physical"].map(g => { const ks = others.filter(k => typeInfo((supportOf(k) || {}).type).group === g); return ks.length ? `<p class="grp-h">${g === "digital" ? "A · Supports digitaux" : "B · Supports physiques"}</p>${block(ks)}` : ""; }).join("");
        return (own.length ? `<h3>Supports de ${esc(L("sources", s.platform))}</h3>${block(own)}` : `<p class="muted">Ce canal n'a pas encore de supports associés : choisissez parmi tous les supports (et associez-les dans Canaux & supports).</p>`)
          + (others.length ? (all ? `<h3>${own.length ? "Autres supports" : "Tous les supports"}</h3>${rest}` : `<button type="button" class="linkbtn" data-allsup>${ic("plus", "xs")} Afficher les autres supports (${others.length})</button>`) : "");
      },
      onClick: (e, s) => {
        if (e.target.closest("[data-allsup]")) { s._allSup = true; return true; }
        const b = e.target.closest("[data-sup]");
        if (b) { s.format = b.dataset.sup; return true; }
      },
      validate: s => s.format ? "" : "Choisissez le support utilisé." },
    { label: "Contenu", question: "Qu'allons-nous communiquer, à qui, et pourquoi ?",
      summary: s => s.topic ? (s.topic.length > 26 ? s.topic.slice(0, 26) + "…" : s.topic) : "",
      render: s => `<div class="form">
        <div class="fld full"><label for="w_topic">Contenu / message <span class="req">*</span></label><textarea id="w_topic" data-f="topic" maxlength="200" placeholder="ex. Les avantages du financement participatif">${esc(s.topic || "")}</textarea></div>
        <div class="fld"><label for="w_date">Date <span class="req">*</span></label><input type="date" id="w_date" data-f="date" value="${esc(s.date || S.today)}"></div>
        <div class="fld"><label for="w_link">Lien (facultatif)</label><input type="url" id="w_link" data-f="link" value="${esc(s.link || "")}" placeholder="https://"></div>
        <div class="fld full"><label>Cible</label><div class="choices sm">${Object.entries(EN("audiences")).map(([k, l]) => choice("data-aud", k, l, { on: s.audience === k })).join("")}</div></div>
        <div class="fld full"><label>Objectif</label><div class="choices obj">${Object.entries(EN("ad_objectives")).map(([k, l]) => choice("data-obj", k, l, { icon: objTile(k), sub: "KPI : " + (OBJ_KPI[k] || []).map(x => KPI_L[x]).join(", "), on: s.objective === k })).join("")}</div></div></div>`,
      collect: (root, s) => { for (const el of $$("#wizStep [data-f]", root)) s[el.dataset.f] = el.value; },
      onClick: (e, s) => {
        const a = e.target.closest("[data-aud]"), o = e.target.closest("[data-obj]");
        if (a) { s.audience = s.audience === a.dataset.aud ? "" : a.dataset.aud; return true; }
        if (o) { s.objective = s.objective === o.dataset.obj ? "" : o.dataset.obj; return true; }
      },
      validate: s => !String(s.topic || "").trim() ? "Décrivez le contenu ou le message." : !isDate(s.date) ? "Indiquez une date valide." : "" },
    { label: "Résultats", question: "Comment mesurer le résultat ? Vous pouvez compléter plus tard.",
      summary: s => { const k = (OBJ_KPI[s.objective] || ["reach"])[0]; return +s[k] > 0 ? KPI_L[k] + " : " + kpiFmt(k, s[k]) : ""; },
      render: s => {
        const main = OBJ_KPI[s.objective] || ["reach", "engagement", "clicks"];
        const rest = KPI_KEYS.filter(k => !main.includes(k));
        const inp = (k, big) => `<div class="fld${big ? " big" : ""}"><label for="w_${k}">${KPI_L[k]}${k === "cost" ? " (" + esc(cur()) + ")" : ""}</label><input type="number" min="0" step="${k === "cost" ? "any" : "1"}" inputmode="${k === "cost" ? "decimal" : "numeric"}" id="w_${k}" data-f="${k}" value="${s[k] === "" || s[k] == null ? "" : esc(s[k])}" placeholder="—"></div>`;
        const open = s._moreKpi || rest.some(k => +s[k] > 0);
        return `<p class="kpi-main">${s.objective ? `${objTile(s.objective, "sm")} Objectif <b>${esc(L("ad_objectives", s.objective))}</b> → ${main.length > 1 ? "KPI principaux" : "KPI principal"}` : "Indicateurs principaux"}</p>
          <div class="form">${main.map(k => inp(k, true)).join("")}</div>
          <details class="more"${open ? " open" : ""}><summary>Autres indicateurs (${rest.length})</summary><div class="form">${rest.map(k => inp(k, false)).join("")}</div></details>
          <div class="rates" id="wRates">${ratesHtml(s)}</div>`;
      },
      collect: (root, s) => { for (const el of $$("#wizStep [data-f]", root)) s[el.dataset.f] = el.value; const d = $("details.more", root); s._moreKpi = !!(d && d.open); },
      onInput: (e, s, root) => { if (e.target.dataset.f) { s[e.target.dataset.f] = e.target.value; const r = $("#wRates", root); if (r) r.innerHTML = ratesHtml(s); } },
      validate: s => KPI_KEYS.some(k => s[k] !== "" && s[k] != null && (isNaN(+s[k]) || +s[k] < 0)) ? "Les indicateurs doivent être des nombres positifs." : "" },
    { label: "Vérifier", question: "Tout est correct ?", render: s => actionReview(s) },
  ];
  openWizard({
    title: row ? "Modifier l'action de communication" : "Nouvelle action de communication",
    steps, state: st, start: row ? 4 : (st.platform ? 1 : 0), reached: row ? 4 : (st.platform ? 1 : 0),
    finish: row ? "Enregistrer les modifications" : "Enregistrer l'action", again: row ? "" : "Enregistrer et en créer une autre",
    foot: () => row ? `<button type="button" class="btn del" data-del="posts:${row.id}">Supprimer</button>` : "",
    onFinish: async (s, again) => {
      const data = {};
      for (const k of Object.keys(SC("posts").fields)) data[k] = s[k] == null ? "" : s[k];
      for (const k of KPI_KEYS) if (data[k] === "") data[k] = 0;
      await api("save", {}, { entity: "posts", id: row ? +row.id : 0, row: data });
      toast(row ? "Action modifiée" : "Action enregistrée");
      afterMutation("posts");
      if (again) openActionWizard(null, { date: s.date, platform: s.platform, category: s.category, audience: s.audience, objective: s.objective });
      else closeModal();
    },
  });
}

/* ---------- niveaux 1 et 2 : gestion des canaux et des supports */
const taxFresh = () => ({ channels: JSON.parse(JSON.stringify(CHANNELS())), supports: JSON.parse(JSON.stringify(SUPPORTS())), dirty: false, open: {}, openCh: null, level: "channels", q: "", usage: { channels: {}, supports: {} } });
/** Cases à cocher des supports, par groupe puis par type ; un type s'ouvre s'il a des supports cochés. */
function supportChecklist(list, selected, attr = "") {
  return ["digital", "physical"].map(g => {
    const types = TX().support_types.filter(t => t.group === g).map(t => [t, list.filter(x => x.type === t.key)]).filter(x => x[1].length);
    if (!types.length) return "";
    return `<p class="grp-h">${g === "digital" ? "A · Supports digitaux" : "B · Supports physiques"}</p>` + types.map(([t, sups]) => {
      const n = sups.filter(x => selected.includes(x.key)).length;
      return `<details class="ch-cat"${n ? " open" : ""}><summary>${esc(t.label)}${n ? ` <span class="pill blue">${n}</span>` : ""}</summary><div class="ch-list">${sups.map(x => `<label><input type="checkbox" data-supck${attr} value="${esc(x.key)}"${selected.includes(x.key) ? " checked" : ""}> ${esc(x.label)}</label>`).join("")}</div></details>`;
    }).join("");
  }).join("");
}
function openChannelWizard(presetCat) {
  const tx = S.tx;
  const st = { category: presetCat || "", label: "", supports: null };
  const examples = { social: "Threads", web: "Médium", messaging: "Viber", ads: "Snapchat Ads", media: "Podcast radio", outdoor: "Abribus", direct: "Porte-à-porte", events: "Forum investisseurs", pr: "Communiqué de presse", retail: "Agence" };
  openWizard({
    title: "Nouveau canal de communication", finish: "Ajouter le canal",
    state: st,
    steps: [
      { label: "Catégorie", question: "Dans quelle famille de canaux ?", summary: s => s.category ? catInfo(s.category).label : "",
        render: s => `<div class="choices cats">${TX().categories.map(c => choice("data-cat", c.key, c.label, { icon: catTile(c.key), sub: tx.channels.filter(x => x.category === c.key).length + " canal(aux)", on: s.category === c.key })).join("")}</div>`,
        onClick: (e, s) => { const c = e.target.closest("[data-cat]"); if (c) { if (s.category !== c.dataset.cat) s.supports = null; s.category = c.dataset.cat; return true; } },
        validate: s => s.category ? "" : "Choisissez une catégorie." },
      { label: "Canal", question: "Quel est le nom du canal ?", summary: s => s.label,
        render: s => `<div class="fld"><label for="w_label">Nom du canal <span class="req">*</span></label><input type="text" id="w_label" value="${esc(s.label)}" maxlength="50" placeholder="ex. ${esc(examples[s.category] || "Nouveau canal")}"></div>
          <p class="small muted">Déjà dans ${catIc(s.category) + " " + esc(catInfo(s.category).label)} : ${tx.channels.filter(x => x.category === s.category).map(x => esc(x.label)).join(", ") || "aucun canal"}.</p>`,
        collect: (root, s) => { const i = $("#w_label", root); if (i) s.label = i.value.trim(); },
        validate: s => !s.label ? "Donnez un nom au canal." : tx.channels.some(x => x.label.toLowerCase() === s.label.toLowerCase()) ? "Un canal porte déjà ce nom." : "" },
      { label: "Supports", question: "Sous quelles formes communiquerez-vous sur ce canal ?", summary: s => (s.supports || []).length + " support(s)",
        render: s => {
          if (!s.supports) s.supports = (TX().category_supports[s.category] || []).filter(k => tx.supports.some(x => x.key === k));
          return `<p class="small muted" style="margin-top:0">Proposés pour ${esc(catInfo(s.category).label)} : cochez ou décochez ce qui convient.</p>${supportChecklist(tx.supports, s.supports)}`;
        },
        collect: (root, s) => { if ($("[data-supck]", root)) s.supports = $$("[data-supck]:checked", root).map(i => i.value); } },
      { label: "Vérifier", question: "On ajoute ce canal ?",
        render: s => `<div class="review"><div class="rv"><span class="rv-l">Catégorie</span><span class="rv-v">${catIc(s.category) + " " + esc(catInfo(s.category).label)}</span><button type="button" class="linkbtn" data-wiz="goto:0">Modifier</button></div>`
          + `<div class="rv"><span class="rv-l">Canal</span><span class="rv-v"><b>${esc(s.label)}</b></span><button type="button" class="linkbtn" data-wiz="goto:1">Modifier</button></div>`
          + `<div class="rv"><span class="rv-l">Supports</span><span class="rv-v">${(s.supports || []).map(k => `<span class="chan">${esc(L("supports", k))}</span>`).join("") || '<span class="muted">aucun</span>'}</span><button type="button" class="linkbtn" data-wiz="goto:2">Modifier</button></div></div>` },
    ],
    onFinish: async s => {
      const item = { key: uniqueKey(slugify(s.label), tx.channels), label: s.label, category: s.category, supports: s.supports || [] };
      tx.channels.push(item);
      tx.open[s.category] = true;
      try { await saveTaxonomy(); } catch (e) { tx.channels.splice(tx.channels.indexOf(item), 1); throw e; }
      closeModal();
    },
  });
}
async function saveTaxonomy() {
  const tx = S.tx;
  if (tx.channels.some(c => !c.label.trim()) || tx.supports.some(x => !x.label.trim())) throw new Error("Donnez un nom à chaque canal et à chaque support.");
  const d = await api("settings_save", {}, {
    supports: tx.supports.map(x => ({ key: x.key, label: x.label.trim(), type: x.type })),
    channels: tx.channels.map(c => ({ key: c.key, label: c.label.trim(), category: c.category, supports: c.supports })),
  });
  S.boot.settings = d.settings;
  S.boot.enums = d.enums;
  S.cache.clear();
  S.tx = Object.assign(taxFresh(), { open: tx.open, level: tx.level, q: tx.q, openCh: tx.openCh });
  S.tx.usage = await api("taxonomy_usage");
  toast("Canaux & supports enregistrés");
  drawTax();
}
function drawTax() {
  const tx = S.tx, root = $("#taxRoot");
  if (!tx || !root) return;
  const q = tx.q.trim().toLowerCase();
  const levels = `<div class="levels">
    <button type="button" class="lvl${tx.level === "channels" ? " on" : ""}" data-lvl="channels"><span class="n">1</span><span><b>Canaux</b><small>Où, par quel moyen ? · ex. Instagram</small></span><em>${tx.channels.length}</em></button>
    <button type="button" class="lvl${tx.level === "supports" ? " on" : ""}" data-lvl="supports"><span class="n">2</span><span><b>Supports</b><small>Sous quelle forme ? · ex. Reel, carrousel</small></span><em>${tx.supports.length}</em></button>
    <a class="lvl" href="#comm/performance"><span class="n">3</span><span><b>Performance</b><small>Quel canal, quel support fonctionne ?</small></span><em>→</em></a></div>`;
  const toolbar = `<div class="toolbar"><label class="field-search">${ic("search", "sm")}<input type="search" id="txQ" placeholder="${tx.level === "channels" ? "Rechercher un canal…" : "Rechercher un support…"}" value="${esc(tx.q)}" aria-label="Rechercher"></label><span class="grow"></span>${tx.level === "channels" ? `<button type="button" class="btn pri" data-tx="newch">${ic("plus", "sm")} Nouveau canal</button>` : ""}</div>`;
  const ctrl = (i, kind) => `<span class="ctrl"><button type="button" class="icon-btn" data-tx="up:${kind}:${i}" title="Monter" aria-label="Monter">${ic("chev-u", "sm")}</button><button type="button" class="icon-btn" data-tx="down:${kind}:${i}" title="Descendre" aria-label="Descendre">${ic("chev-d", "sm")}</button><button type="button" class="icon-btn del" data-tx="del:${kind}:${i}" title="Retirer" aria-label="Retirer">${ic("trash", "sm")}</button></span>`;
  let body;
  if (tx.level === "channels") {
    body = TX().categories.map(c => {
      const list = tx.channels.map((ch, i) => [ch, i]).filter(([ch]) => ch.category === c.key);
      const shown = q ? list.filter(([ch]) => ch.label.toLowerCase().includes(q)) : list;
      if (q && !shown.length) return "";
      const rows = shown.map(([ch, i]) => {
        const n = tx.usage.channels[ch.key] || 0, open = tx.openCh === ch.key;
        return `<div class="tx-row${open ? " open" : ""}"><input type="text" class="lbl" data-chlabel="${i}" value="${esc(ch.label)}" maxlength="50" aria-label="Nom du canal">
          <button type="button" class="pillbtn" data-tx="sup:${i}" aria-expanded="${open}">${ic("layers", "xs")} ${ch.supports.length} support(s) ${ic(open ? "chev-u" : "chev-d", "xs")}</button>
          <select data-chcat="${i}" aria-label="Catégorie">${TX().categories.map(x => `<option value="${x.key}"${x.key === ch.category ? " selected" : ""}>${esc(x.label)}</option>`).join("")}</select>
          <span class="usage">${n ? num(n) + " donnée(s)" : ""}</span>${ctrl(i, "ch")}
          ${open ? `<div class="tx-sup" data-chsup="${i}"><p class="small muted" style="margin:0 0 4px">Supports utilisés sur <b>${esc(ch.label)}</b> :</p>${supportChecklist(tx.supports, ch.supports)}</div>` : ""}</div>`;
      }).join("");
      return `<details class="tx-grp" data-grp="${c.key}"${q || tx.open[c.key] ? " open" : ""}><summary>${catTile(c.key)}<b>${esc(c.label)}</b><span class="cnt">${list.length} canal(aux)</span></summary>
        <div class="tx-rows">${rows || '<p class="muted small" style="margin:6px 0">Aucun canal dans cette catégorie.</p>'}</div>
        <div class="tx-add"><input type="text" data-addch="${c.key}" placeholder="Nouveau canal dans ${esc(c.label)}…" maxlength="50" aria-label="Nouveau canal"><button type="button" class="btn sm" data-tx="addch:${c.key}">${ic("plus", "sm")} Ajouter</button></div></details>`;
    }).join("") || '<div class="empty">Aucun canal ne correspond.</div>';
  } else {
    body = ["digital", "physical"].map(g => `<h3>${g === "digital" ? "A · Supports digitaux" : "B · Supports physiques"}</h3>` + TX().support_types.filter(t => t.group === g).map(t => {
      const list = tx.supports.map((x, i) => [x, i]).filter(([x]) => x.type === t.key);
      const shown = q ? list.filter(([x]) => x.label.toLowerCase().includes(q)) : list;
      if (q && !shown.length) return "";
      const rows = shown.map(([x, i]) => {
        const n = tx.usage.supports[x.key] || 0, on = tx.channels.filter(c => c.supports.includes(x.key)).length;
        return `<div class="tx-row"><input type="text" class="lbl" data-suplabel="${i}" value="${esc(x.label)}" maxlength="50" aria-label="Nom du support">
          <span class="pillbtn static" title="Canaux qui proposent ce support">${ic("megaphone", "xs")} ${on} canal(aux)</span>
          <select data-suptype="${i}" aria-label="Type de support">${TX().support_types.map(y => `<option value="${y.key}"${y.key === x.type ? " selected" : ""}>${esc(y.label)}</option>`).join("")}</select>
          <span class="usage">${n ? num(n) + " donnée(s)" : ""}</span>${ctrl(i, "sup")}</div>`;
      }).join("");
      return `<details class="tx-grp" data-grp="t_${t.key}"${q || tx.open["t_" + t.key] ? " open" : ""}><summary><b>${esc(t.label)}</b><span class="cnt">${list.length} support(s)</span></summary>
        <div class="tx-rows">${rows || '<p class="muted small" style="margin:6px 0">Aucun support de ce type.</p>'}</div>
        <div class="tx-add"><input type="text" data-addsup="${t.key}" placeholder="Nouveau support (${esc(t.label)})…" maxlength="50" aria-label="Nouveau support"><button type="button" class="btn sm" data-tx="addsup:${t.key}">${ic("plus", "sm")} Ajouter</button></div></details>`;
    }).join("")).join("");
  }
  root.innerHTML = levels + toolbar + body
    + `<div class="savebar"${tx.dirty ? "" : " hidden"}><span>Modifications non enregistrées</span><span class="grow"></span><button type="button" class="btn" data-tx="cancel">Annuler</button><button type="button" class="btn pri" data-tx="save">Enregistrer</button></div>`;
}
function bindTax() {
  const root = $("#taxRoot");
  const tx = () => S.tx;
  const dirty = () => { tx().dirty = true; const b = $(".savebar", root); if (b) b.hidden = false; };
  const moveIn = (list, i, d, same) => {
    let j = i + d;
    while (j >= 0 && j < list.length && !same(list[j], list[i])) j += d;
    if (j < 0 || j >= list.length) return false;
    [list[i], list[j]] = [list[j], list[i]];
    return true;
  };
  root.addEventListener("toggle", e => { const g = e.target.closest && e.target.closest("[data-grp]"); if (g === e.target && !tx().q) tx().open[g.dataset.grp] = g.open; }, true);
  root.addEventListener("input", e => {
    const t = e.target;
    if (t.id === "txQ") { tx().q = t.value; drawTax(); const q = $("#txQ"); q.focus(); q.setSelectionRange(q.value.length, q.value.length); return; }
    if (t.dataset.chlabel != null) { tx().channels[+t.dataset.chlabel].label = t.value; dirty(); }
    if (t.dataset.suplabel != null) { tx().supports[+t.dataset.suplabel].label = t.value; dirty(); }
  });
  root.addEventListener("change", e => {
    const t = e.target;
    if (t.dataset.chcat != null) { const c = tx().channels[+t.dataset.chcat]; c.category = t.value; tx().open[t.value] = true; dirty(); drawTax(); }
    if (t.dataset.suptype != null) { const x = tx().supports[+t.dataset.suptype]; x.type = t.value; tx().open["t_" + t.value] = true; dirty(); drawTax(); }
    if (t.hasAttribute("data-supck")) {
      const box = t.closest("[data-chsup]");
      tx().channels[+box.dataset.chsup].supports = $$("[data-supck]:checked", box).map(i => i.value);
      dirty();
      const btn = $(`[data-tx="sup:${box.dataset.chsup}"]`, root);
      if (btn) btn.innerHTML = `${ic("layers", "xs")} ${tx().channels[+box.dataset.chsup].supports.length} support(s) ${ic("chev-u", "xs")}`;
    }
  });
  const add = (kind, key) => {
    const inp = $(`[data-add${kind}="${key}"]`, root);
    const label = (inp.value || "").trim();
    if (!label) return inp.focus();
    const list = kind === "ch" ? tx().channels : tx().supports;
    if (list.some(x => x.label.toLowerCase() === label.toLowerCase())) return toast(`« ${label} » existe déjà.`);
    if (kind === "ch") { list.push({ key: uniqueKey(slugify(label), list), label, category: key, supports: (TX().category_supports[key] || []).filter(k => tx().supports.some(x => x.key === k)) }); tx().open[key] = true; }
    else { list.push({ key: uniqueKey(slugify(label), list), label, type: key }); tx().open["t_" + key] = true; }
    dirty();
    drawTax();
    const again = $(`[data-add${kind}="${key}"]`, root);
    if (again) again.focus();
  };
  root.addEventListener("keydown", e => {
    const t = e.target;
    if (e.key !== "Enter") return;
    if (t.dataset.addch) { e.preventDefault(); add("ch", t.dataset.addch); }
    if (t.dataset.addsup) { e.preventDefault(); add("sup", t.dataset.addsup); }
  });
  root.addEventListener("click", async e => {
    const lv = e.target.closest("[data-lvl]");
    if (lv) { tx().level = lv.dataset.lvl; tx().q = ""; drawTax(); return; }
    const b = e.target.closest("[data-tx]");
    if (!b) return;
    const [a, k, i] = b.dataset.tx.split(":");
    const T = tx();
    if (a === "newch") return openChannelWizard();
    if (a === "addch") return add("ch", k);
    if (a === "addsup") return add("sup", k);
    if (a === "sup") { const key = T.channels[+k].key; T.openCh = T.openCh === key ? null : key; return drawTax(); }
    if (a === "up" || a === "down") {
      const list = k === "ch" ? T.channels : T.supports;
      const same = k === "ch" ? (x, y) => x.category === y.category : (x, y) => x.type === y.type;
      if (moveIn(list, +i, a === "up" ? -1 : 1, same)) { dirty(); drawTax(); }
      return;
    }
    if (a === "del" && k === "ch") {
      const c = T.channels[+i], n = T.usage.channels[c.key] || 0;
      if (T.channels.length <= 1) return toast("Impossible de retirer le dernier canal : gardez-en au moins un.", "err");
      if (n && !(await askDialog({ icon: ["trash", "pink"], title: `Retirer le canal « ${c.label} » ?`, text: `${n} donnée(s) le citent (actions, leads, campagnes…). Elles garderont son nom, mais vous ne pourrez plus le choisir pour de nouvelles saisies.`, ok: "Retirer le canal", cancel: "Garder le canal", danger: true }))) return;
      T.channels.splice(+i, 1); dirty(); return drawTax();
    }
    if (a === "del" && k === "sup") {
      const x = T.supports[+i], n = T.usage.supports[x.key] || 0, on = T.channels.filter(c => c.supports.includes(x.key)).length;
      if (T.supports.length <= 1) return toast("Impossible de retirer le dernier support : gardez-en au moins un.", "err");
      if ((n || on) && !(await askDialog({ icon: ["trash", "pink"], title: `Retirer le support « ${x.label} » ?`, text: `Il est ${on ? `proposé par ${on} canal(aux)` : ""}${on && n ? " et " : ""}${n ? `cité par ${n} donnée(s)` : ""}. Les données déjà saisies garderont son nom.`, ok: "Retirer le support", cancel: "Garder le support", danger: true }))) return;
      T.supports.splice(+i, 1);
      for (const c of T.channels) c.supports = c.supports.filter(s2 => s2 !== x.key);
      dirty(); return drawTax();
    }
    if (a === "cancel") { S.tx = Object.assign(taxFresh(), { open: T.open, level: T.level, usage: T.usage }); return drawTax(); }
    if (a === "save") { b.disabled = true; try { await saveTaxonomy(); } catch (err) { toast(err.message); b.disabled = false; } }
  });
}

/* ---------- niveau 3 : performance par catégorie → canal → support */
const PERF_METRICS = [["leads", "Leads"], ["reach", "Portée"], ["engagement", "Engagement"], ["eng_rate", "Taux d'engagement"], ["clicks", "Clics"], ["conversions", "Conversions"], ["cost", "Coût"], ["cpl", "Coût par lead"]];
const leadsOf = x => Math.max(+x.leads || 0, +x.tracked || 0);
const metricOf = (x, m) => m === "leads" ? leadsOf(x) : x[m];
const metricFmt = (m, v) => v == null ? "—" : ["eng_rate", "ctr", "qual_rate"].includes(m) ? pct(v, 1) : ["cost", "cpl", "cpa"].includes(m) ? money(v) : num(v);
const perfCells = x => [num(x.actions), num(x.reach), num(x.engagement), pct(x.eng_rate, 1), num(x.clicks), pct(x.ctr, 2), num(x.leads), num(x.tracked) + (x.tracked ? `<div class="sub">${pct(x.qual_rate)} qualifiés</div>` : ""), num(x.conversions), +x.cost ? money(x.cost) : "—", money(x.cpl)];
const PERF_HEAD = ["Actions", "Portée", "Engagement", "Taux eng.", "Clics", "CTR", "Leads déclarés", "Leads suivis", "Conversions", "Coût", "Coût / lead"];
/** Tableau dépliable : catégorie ▸ canaux ▸ supports. */
function perfTable(perf, open) {
  if (!perf.categories.length) return empty("Aucune donnée de communication sur la période : enregistrez des actions (assistant), des résultats publicitaires ou des leads.");
  const row = (cls, name, x, toggle, depth) => `<tr class="pt ${cls}">${`<td class="pt-name" style="padding-left:${10 + depth * 18}px">${toggle ? `<button type="button" class="tgl" data-pt="${esc(toggle)}" aria-expanded="${!!open[toggle]}" aria-label="Déplier">${ic(open[toggle] ? "chev-d" : "chev-r", "xs")}</button>` : '<span class="tgl-sp"></span>'}${name}</td>`}${perfCells(x).map(c => `<td class="n">${c}</td>`).join("")}</tr>`;
  let body = "";
  for (const c of perf.categories) {
    body += row("pt-cat", `${catIc(c.key)} <b>${esc(c.label)}</b> <span class="muted small">${c.channels.length} canal(aux)</span>`, c, "c:" + c.key, 0);
    if (!open["c:" + c.key]) continue;
    for (const ch of c.channels) {
      const real = ch.supports.filter(x => x.key !== "");
      body += row("pt-ch", `<b>${esc(ch.label)}</b>${ch.supports.length ? ` <span class="muted small">${ch.supports.length} support(s)</span>` : ""}`, ch, ch.supports.length ? "h:" + ch.key : "", 1);
      if (!open["h:" + ch.key]) continue;
      for (const x of ch.supports) body += row("pt-sup", `<span class="chan">${esc(x.label)}</span>`, x, "", 2);
      if (!real.length) body += `<tr class="pt pt-sup"><td colspan="12" class="muted small" style="padding-left:56px">Support non précisé : renseignez le support des actions et des leads pour comparer les formats.</td></tr>`;
    }
  }
  const t = perf.totals;
  return `<div class="tw"><table class="perf"><thead><tr><th>Catégorie · canal · support</th>${PERF_HEAD.map(h => `<th class="n">${h}</th>`).join("")}</tr></thead><tbody>${body}</tbody>
    <tfoot><tr><td>Total</td>${perfCells(t).map(c => `<td class="n">${c}</td>`).join("")}</tr></tfoot></table></div>`;
}
/** Résumé pour les rapports : les canaux qui ont donné des résultats, avec leur meilleur support. */
function commSummary(perf) {
  if (!perf || !perf.categories.length) return empty("Aucune action de communication sur la période.");
  const rows = perf.categories.flatMap(c => c.channels.map(ch => Object.assign({ cat: c }, ch))).sort((a, b) => leadsOf(b) - leadsOf(a) || b.reach - a.reach).slice(0, 10);
  return table(rows, [
    { l: "Canal", h: x => `<span class="chan-cat">${catIc(x.cat.key)}<b>${esc(x.label)}</b></span><div class="sub">${esc(x.cat.label)}</div>` },
    { l: "Actions", n: 1, h: x => num(x.actions) }, { l: "Portée", n: 1, h: x => num(x.reach) }, { l: "Taux eng.", n: 1, h: x => pct(x.eng_rate, 1) },
    { l: "Clics", n: 1, h: x => num(x.clicks) }, { l: "Leads", n: 1, h: x => num(leadsOf(x)) }, { l: "Coût", n: 1, h: x => +x.cost ? money(x.cost) : "—" },
    { l: "Meilleur support", h: x => { const b = x.supports.find(y => y.key !== ""); return b ? `<span class="chan">${esc(b.label)}</span>` : '<span class="muted">—</span>'; } },
  ]);
}
VIEWS["comm/performance"] = { range: true, fn: async () => {
  const [from, to] = curRange();
  S.perf = await cached("performance", { from, to });
  S.perfUi = S.perfUi || { metric: "leads", ch: "", open: {} };
  if (!Object.keys(S.perfUi.open).length && S.perf.perf.categories[0]) S.perfUi.open["c:" + S.perf.perf.categories[0].key] = true;
  return `<div id="perfRoot"></div>`;
}, after: () => {
  drawPerf();
  $("#perfRoot").addEventListener("click", e => {
    const m = e.target.closest("[data-pm]"), t = e.target.closest("[data-pt]");
    if (m) { S.perfUi.metric = m.dataset.pm; drawPerf(); }
    if (t) { S.perfUi.open[t.dataset.pt] = !S.perfUi.open[t.dataset.pt]; drawPerf(); }
  });
  $("#perfRoot").addEventListener("change", e => { if (e.target.id === "perfCh") { S.perfUi.ch = e.target.value; drawPerf(); } });
} };
function drawPerf() {
  const { perf, prev, period } = S.perf, ui = S.perfUi, t = perf.totals, root = $("#perfRoot");
  if (!root) return;
  const k = (label, key, fmt = "int", better = "up") => kpiTile({ key: "", label, value: key === "leads_all" ? leadsOf(t) : t[key], prev: prev ? (key === "leads_all" ? leadsOf(prev) : prev[key]) : null, fmt, better });
  const mLabel = (PERF_METRICS.find(x => x[0] === ui.metric) || PERF_METRICS[0])[1];
  const chips = `<div class="chips">${PERF_METRICS.map(([key, l]) => `<button type="button" class="chip${ui.metric === key ? " on" : ""}" data-pm="${key}">${esc(l)}</button>`).join("")}</div>`;
  const all = perf.categories.flatMap(c => c.channels.map(ch => Object.assign({ cat: c }, ch)));
  const bars = list => hbars(list.filter(x => metricOf(x, ui.metric) != null && (metricOf(x, ui.metric) > 0)).sort((a, b) => ui.metric === "cpl" ? metricOf(a, ui.metric) - metricOf(b, ui.metric) : metricOf(b, ui.metric) - metricOf(a, ui.metric)).slice(0, 15)
    .map(x => ({ label: x.label, value: metricOf(x, ui.metric), tip: `${esc(mLabel)} : ${metricFmt(ui.metric, metricOf(x, ui.metric))}<br>Portée ${num(x.reach)} · Leads ${num(leadsOf(x))}` })), v => metricFmt(ui.metric, v));
  const withSup = all.filter(x => x.supports.filter(y => y.key !== "").length >= 1);
  if (!withSup.some(x => x.key === ui.ch)) ui.ch = (withSup.slice().sort((a, b) => b.supports.length - a.supports.length)[0] || {}).key || "";
  const chSel = withSup.find(x => x.key === ui.ch);
  root.innerHTML = `<p class="muted" style="margin-top:0">Niveau 3 · performance de la communication, ${esc(rangeLabel())}. Sources : actions de communication, résultats d'Ads Manager (Publicité digitale), campagnes email (canal Email) et leads suivis dans Leads & Contacts.</p>
    <div class="kpis">${k("Actions", "actions")}${k("Portée", "reach")}${k("Engagement", "engagement")}${k("Taux d'engagement", "eng_rate", "pct")}${k("Clics", "clicks")}${k("Leads", "leads_all")}${k("Conversions", "conversions")}${k("Coût", "cost", "money", "neutral")}${k("Coût par lead", "cpl", "money", "down")}</div>
    ${insightsCard(perf.insights, "Où concentrer les efforts")}
    <div class="grid2">
      ${card("Comparer les canaux", chips + bars(all))}
      ${card("Comparer les supports d'un canal", withSup.length ? `<div class="toolbar" style="margin-bottom:10px"><select id="perfCh" aria-label="Canal">${withSup.map(x => `<option value="${esc(x.key)}"${x.key === ui.ch ? " selected" : ""}>${esc(x.cat.icon + " " + x.label)}</option>`).join("")}</select><span class="muted small">${esc(mLabel)}</span></div>${bars(chSel.supports.filter(y => y.key !== ""))}` : empty("Renseignez le support de vos actions pour comparer les formats (ex. Reel contre carrousel)."))}
    </div>
    ${card("Détail · catégorie ▸ canal ▸ support", perfTable(perf, ui.open), `<a class="btn sm" href="#comm/actions">Actions</a>`)}`;
}

/* ---------- liste des actions de communication */
VIEWS["comm/actions"] = { range: true, fn: async param => {
  const sel = param || "all";
  const rows = await rangeList("posts");
  const counts = {};
  for (const r of rows) { const c = catOf(r.platform) || "other"; counts[c] = (counts[c] || 0) + 1; }
  const list = sel === "all" ? rows : rows.filter(r => (catOf(r.platform) || "other") === sel);
  const sum = key => list.reduce((a, r) => a + (+r[key] || 0), 0);
  const chips = `<div class="chips"><a class="chip${sel === "all" ? " on" : ""}" href="#comm/actions">Toutes (${num(rows.length)})</a>${TX().categories.filter(c => counts[c.key]).map(c => `<a class="chip${sel === c.key ? " on" : ""}" href="#comm/actions/${c.key}">${catIc(c.key)} ${esc(c.label)} (${num(counts[c.key])})</a>`).join("")}${counts.other ? `<a class="chip${sel === "other" ? " on" : ""}" href="#comm/actions/other">Canaux retirés (${num(counts.other)})</a>` : ""}</div>`;
  const kt = (l, v, m, sub = "&nbsp;") => `<div class="kpi">${tile(m[0], m[1])}<small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  return `<p class="muted" style="margin-top:0">Chaque action suit <b>Catégorie → Canal → Support → Contenu → Cible → Objectif → KPI</b> · ${esc(rangeLabel())}.</p>
    <div class="toolbar">${addBtn("posts", "Nouvelle action", TX().categories.some(c => c.key === sel) ? { category: sel } : null, "btn pri")}<a class="btn" href="#comm/performance">${ic("chart", "sm")} Voir la performance</a><span class="grow"></span>${exportBtn("posts")}</div>${chips}
    <div class="kpis">${kt("Actions", num(list.length), ["megaphone", "violet"])}${kt("Portée", num(sum("reach")), ["eye", "blue"])}${kt("Engagement", num(sum("engagement")), ["heart", "pink"], pct(ratio(sum("engagement"), sum("reach")), 1) + " de la portée")}${kt("Clics", num(sum("clicks")), ["link", "teal"])}${kt("Leads", num(sum("leads")), ["user-plus", "orange"])}${kt("Coût", money(sum("cost")), ["wallet", "amber"], sum("leads") ? money(sum("cost") / sum("leads")) + " / lead" : "&nbsp;")}</div>`
    + card("📣 Actions de communication", table(list, actionCols(), { entity: "posts", emptyMsg: "Aucune action sur la période. Une publication, un email, un salon… : enregistrez-la avec « Nouvelle action », l'assistant vous guide en 5 étapes." }));
} };
VIEWS["comm/taxonomy"] = { fn: async param => {
  if (!S.tx || !S.tx.dirty) S.tx = Object.assign(taxFresh(), S.tx ? { open: S.tx.open, level: S.tx.level } : {});
  if (param === "supports" || param === "channels") S.tx.level = param;
  S.tx.usage = await api("taxonomy_usage");
  return `<p class="muted" style="margin-top:0">Un <b>canal</b> est le moyen utilisé pour transmettre le message (Instagram, Email, Salons…) ; un <b>support</b> est la forme concrète du message (Reel, Newsletter, Brochure…). Chaque canal propose ses supports.</p><div id="taxRoot"></div>`;
}, after: () => { drawTax(); bindTax(); } };

/* ================================================================ formulaires */
function openModal(html) { $("#modalBox").innerHTML = html; $("#modal").hidden = false; const f = $("#modalBox input:not([type=hidden]), #modalBox select, #modalBox textarea"); if (f) f.focus(); }
function closeModal() { $("#modal").hidden = true; $("#modalBox").innerHTML = ""; }

/* ================================================================ boîte de dialogue (remplace confirm / prompt) */
/**
 * Pose une question dans une petite fenêtre. Renvoie une promesse :
 * true / false, ou le texte saisi (null si annulé) quand `input` est fourni.
 * opt = { icon: [nom, teinte], title, text, input: { value, label, placeholder, rows }, ok, cancel, danger }
 */
function askDialog(opt) {
  return new Promise(resolve => {
    const box = $("#dlgBox"), wrap = $("#dlg");
    const prevFocus = document.activeElement;
    const inp = opt.input ? (opt.input.rows ? `<textarea id="dlgIn" rows="${opt.input.rows}" aria-label="${esc(opt.input.label || opt.title)}" placeholder="${esc(opt.input.placeholder || "")}">${esc(opt.input.value || "")}</textarea>`
      : `<input type="text" id="dlgIn" aria-label="${esc(opt.input.label || opt.title)}" value="${esc(opt.input.value || "")}" placeholder="${esc(opt.input.placeholder || "")}">`) : "";
    box.setAttribute("aria-labelledby", "dlgT");
    box.innerHTML = `${opt.icon ? tile(opt.icon[0], opt.icon[1]) : ""}<h2 id="dlgT">${esc(opt.title)}</h2>${opt.text ? `<p>${esc(opt.text)}</p>` : ""}${inp}
      <div class="form-foot"><button type="button" class="btn" data-dlg="0">${esc(opt.cancel || "Annuler")}</button><button type="button" class="btn ${opt.danger ? "danger" : "pri"}" data-dlg="1">${esc(opt.ok || "Continuer")}</button></div>`;
    wrap.hidden = false;
    const field = $("#dlgIn", box);
    (field || $('[data-dlg="1"]', box)).focus();
    if (field && field.select) field.select();
    const done = yes => {
      wrap.hidden = true;
      box.innerHTML = "";
      wrap.removeEventListener("click", onClick);
      document.removeEventListener("keydown", onKey, true);
      if (prevFocus && prevFocus.focus) prevFocus.focus();
      resolve(opt.input ? (yes ? field.value : null) : yes);
    };
    const onClick = e => {
      const b = e.target.closest("[data-dlg]");
      if (b) done(b.dataset.dlg === "1");
      else if (e.target === wrap) done(false);
    };
    const onKey = e => {
      if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); done(false); }
      else if (e.key === "Enter" && !(field && field.tagName === "TEXTAREA") && box.contains(document.activeElement) && document.activeElement.dataset.dlg !== "0") { e.preventDefault(); done(true); }
    };
    wrap.addEventListener("click", onClick);
    document.addEventListener("keydown", onKey, true);
  });
}

/* ================================================================ import de leads (CSV / Excel enregistré en CSV) */
/** Colonnes reconnues : nom de colonne du fichier (sans accents, minuscules) → champ du lead. */
const IMPORT_COLS = {
  date: ["date", "date d'arrivee", "date arrivee", "recu le", "created", "created at"],
  name: ["nom", "nom complet", "name", "full name", "lead", "contact", "prenom nom"],
  phone: ["telephone", "tel", "phone", "mobile", "numero", "gsm"],
  email: ["email", "e-mail", "mail", "adresse email"],
  city: ["ville", "wilaya", "city", "wilaya / ville"],
  type: ["profil", "type", "profile", "investisseur / porteur"],
  source: ["canal", "source", "canal (source)", "channel", "provenance"],
  support: ["support", "format"],
  assigned_to: ["commercial", "commercial responsable", "assigne a", "responsable", "assigned to"],
  status: ["traitement", "statut", "status", "etat"],
  reason: ["pourquoi non qualifie", "raison", "motif", "reason"],
  notes: ["notes", "note", "commentaire", "commentaires", "remarque"],
};
const fold = s => String(s == null ? "" : s).normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/\s+/g, " ").trim();
/** Lit un CSV (séparateur ; ou , détecté, guillemets doublés, retours à la ligne dans les guillemets). */
function parseCsv(text) {
  text = text.replace(/^﻿/, "");
  const first = text.split(/\r?\n/, 1)[0] || "";
  const sep = (first.match(/;/g) || []).length >= (first.match(/,/g) || []).length ? ";" : ",";
  const rows = [];
  let row = [], cell = "", q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; }
      else cell += c;
    } else if (c === '"') q = true;
    else if (c === sep) { row.push(cell); cell = ""; }
    else if (c === "\n" || c === "\r") {
      if (c === "\r" && text[i + 1] === "\n") i++;
      row.push(cell); rows.push(row); row = []; cell = "";
    } else cell += c;
  }
  if (cell !== "" || row.length) { row.push(cell); rows.push(row); }
  return rows.filter(r => r.some(x => String(x).trim() !== ""));
}
/** Retrouve la clé d'une liste à partir d'une valeur du fichier (clé ou libellé, sans tenir compte des accents). */
function matchKey(enumName, v) {
  const f = fold(v);
  if (!f) return "";
  const opts = EN(enumName);
  for (const [k, l] of Object.entries(opts)) if (fold(k) === f || fold(l) === f) return k;
  for (const [k, l] of Object.entries(opts)) if (fold(l).startsWith(f) || f.startsWith(fold(l))) return k;
  return null; // valeur inconnue
}
/** Convertit une date du fichier (AAAA-MM-JJ, JJ/MM/AAAA, JJ-MM-AAAA, JJ.MM.AA) en AAAA-MM-JJ. */
function importDate(v) {
  v = String(v || "").trim();
  if (!v) return S.today;
  if (isDate(v.slice(0, 10))) return v.slice(0, 10);
  const m = v.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/);
  if (!m) return null;
  const y = m[3].length === 2 ? "20" + m[3] : m[3];
  const d = `${y}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}`;
  return isDate(d) && !isNaN(new Date(d + "T12:00:00")) ? d : null;
}
/** Prépare chaque ligne : colonnes reconnues, valeurs converties en clés, problèmes repérés avant l'envoi. */
function prepareImport(table) {
  const head = table[0].map(fold);
  const map = {};
  head.forEach((h, i) => { for (const [k, syn] of Object.entries(IMPORT_COLS)) if (map[k] == null && syn.includes(h)) map[k] = i; });
  const rows = table.slice(1).map((r, n) => {
    const get = k => map[k] == null ? "" : String(r[map[k]] || "").trim();
    const out = { _line: n + 2 }, issues = [];
    out.date = importDate(get("date"));
    if (!out.date) { issues.push(`date « ${get("date")} » illisible`); out.date = S.today; }
    out.name = get("name");
    let block = !out.name;
    if (!out.name) issues.push("nom manquant");
    for (const k of ["phone", "email", "city", "notes"]) out[k] = get(k);
    for (const [k, en, def] of [["type", "contact_types", "investisseur"], ["source", "sources", ""], ["support", "supports", ""], ["assigned_to", "commercials", ""], ["status", "contact_status", "nouveau"], ["reason", "reasons", ""]]) {
      const raw = get(k), key = matchKey(en, raw);
      if (key === null) { issues.push(`${k === "source" ? "canal" : k === "assigned_to" ? "commercial" : k === "type" ? "profil" : k === "status" ? "traitement" : k} « ${raw} » inconnu`); out[k] = def; }
      else out[k] = key || def;
    }
    if (!out.source) { block = true; if (!get("source")) issues.push("canal manquant"); }
    return { data: out, issues, block };
  });
  return { map, rows };
}
function openImport(entity) {
  if (entity !== "contacts") return;
  const cols = ["Date", "Nom complet", "Téléphone", "Email", "Ville", "Profil", "Canal", "Support", "Commercial", "Traitement", "Notes"];
  const sample = [S.today, "Exemple Nom", "0550 00 00 00", "exemple@mail.com", "Alger", L("contact_types", "investisseur"), L("sources", Object.keys(EN("sources"))[0]), "", "", L("contact_status", "nouveau"), ""];
  const tpl = "﻿" + [cols, sample].map(r => r.map(x => /[;"\n]/.test(x) ? `"${String(x).replace(/"/g, '""')}"` : x).join(";")).join("\r\n");
  const tplHref = "data:text/csv;charset=utf-8," + encodeURIComponent(tpl);
  openModal(`<div class="imp">${tile("upload", "green")}<h2>Importer des leads</h2>
    <p class="muted" style="margin-top:-4px">Ajoutez d'un coup les leads d'un fichier Excel ou Google Sheets enregistré au format CSV. Rien n'est enregistré avant votre confirmation.</p>
    <ol class="imp-steps"><li><b>Téléchargez le modèle</b> ou utilisez votre fichier : une ligne par lead, avec au moins le <b>nom</b> et le <b>canal</b>.</li><li><b>Déposez le fichier</b> ci-dessous : vous verrez un aperçu avant l'import.</li></ol>
    <div class="toolbar"><a class="btn sm" href="${tplHref}" download="modele-import-leads.csv">${ic("download", "sm")} Télécharger le modèle</a></div>
    <label class="dropzone" id="impDrop">${ic("upload")}<b>Glissez votre fichier CSV ici</b><span class="muted small">ou cliquez pour le choisir · 1 000 lignes au maximum</span><input type="file" id="impFile" accept=".csv,text/csv,text/plain" hidden></label>
    <div id="impOut"></div>
    <div class="form-foot"><button type="button" class="btn" data-close>Annuler</button><button type="button" class="btn pri" id="impGo" disabled>Importer</button></div></div>`);
  let prepared = null;
  const show = (file) => {
    if (!file) return;
    if (file.size > 3 * 1024 * 1024) { $("#impOut").innerHTML = `<p class="form-err">Ce fichier est trop lourd (plus de 3 Mo). Découpez-le en plusieurs fichiers.</p>`; return; }
    const rd = new FileReader();
    rd.onload = () => {
      const tab = parseCsv(String(rd.result || ""));
      if (tab.length < 2) { $("#impOut").innerHTML = `<p class="form-err">Aucune ligne trouvée. Vérifiez que le fichier est bien au format CSV, avec une ligne de titres puis une ligne par lead.</p>`; $("#impGo").disabled = true; return; }
      prepared = prepareImport(tab);
      const found = Object.keys(prepared.map);
      if (!found.includes("name") || !found.includes("source")) {
        $("#impOut").innerHTML = `<p class="form-err">Les colonnes « Nom complet » et « Canal » sont introuvables. Renommez les titres de colonnes comme dans le modèle, puis réessayez.</p>`;
        $("#impGo").disabled = true; prepared = null; return;
      }
      if (prepared.rows.length > 1000) { $("#impOut").innerHTML = `<p class="form-err">${num(prepared.rows.length)} lignes : c'est plus que les 1 000 autorisées par import. Découpez le fichier en plusieurs parties.</p>`; $("#impGo").disabled = true; prepared = null; return; }
      const bad = prepared.rows.filter(r => r.issues.length);
      const skip = prepared.rows.filter(r => r.block), warn = bad.filter(r => !r.block);
      const ok = prepared.rows.length - bad.length, go = prepared.rows.length - skip.length;
      const labels = { date: "Date", name: "Nom", phone: "Téléphone", email: "Email", city: "Ville", type: "Profil", source: "Canal", support: "Support", assigned_to: "Commercial", status: "Traitement", reason: "Raison", notes: "Notes" };
      $("#impDrop").classList.add("done");
      $("#impDrop").innerHTML = `${ic("file")}<b>${esc(file.name)}</b><span class="muted small">Cliquez pour choisir un autre fichier</span><input type="file" id="impFile" accept=".csv,text/csv,text/plain" hidden>`;
      $("#impOut").innerHTML = `<div class="imp-sum"><span class="pill good">${num(ok)} prête(s)</span>${warn.length ? `<span class="pill warn">${num(warn.length)} avec valeur par défaut</span>` : ""}${skip.length ? `<span class="pill bad">${num(skip.length)} ignorée(s)</span>` : ""}<span class="muted small">Colonnes reconnues : ${found.map(k => esc(labels[k])).join(", ")}</span></div>`
        + (bad.length ? `<p class="small" style="margin:8px 0">${warn.length ? "Une valeur inconnue est remplacée par la valeur par défaut (profil Investisseur, traitement À traiter…). " : ""}${skip.length ? "Une ligne sans nom ou sans canal reconnu est ignorée : corrigez-la dans le fichier, puis importez-la à part." : ""}</p><div class="dl-list">${bad.slice(0, 8).map(r => `<div class="dl-row${r.block ? " block" : ""}">${ic(r.block ? "x" : "alert", "sm")}<span>Ligne ${r.data._line} — ${esc(r.data.name || "sans nom")} : ${esc(r.issues.join(", "))}${r.block ? " · <b>ignorée</b>" : ""}</span></div>`).join("")}${bad.length > 8 ? `<div class="dl-row muted small">… et ${num(bad.length - 8)} autre(s)</div>` : ""}</div>` : "")
        + `<div class="tbl-wrap" style="margin-top:10px"><table><thead><tr><th>Ligne</th><th>Nom</th><th>Profil</th><th>Canal</th><th>Traitement</th></tr></thead><tbody>${prepared.rows.slice(0, 5).map(r => `<tr><td>${r.data._line}</td><td>${esc(r.data.name || "—")}</td><td>${esc(L("contact_types", r.data.type))}</td><td>${esc(L("sources", r.data.source) || "—")}</td><td>${esc(L("contact_status", r.data.status))}</td></tr>`).join("")}</tbody></table></div>${prepared.rows.length > 5 ? `<p class="muted small">Aperçu des 5 premières lignes sur ${num(prepared.rows.length)}.</p>` : ""}`;
      $("#impGo").disabled = !go;
      $("#impGo").textContent = go ? `Importer ${num(go)} lead(s)` : "Aucune ligne à importer";
    };
    rd.onerror = () => { $("#impOut").innerHTML = `<p class="form-err">Le fichier n'a pas pu être lu. Réessayez, ou enregistrez-le de nouveau au format CSV.</p>`; };
    rd.readAsText(file, "utf-8");
  };
  const box = $("#modalBox");
  box.addEventListener("change", e => { if (e.target.id === "impFile") show(e.target.files[0]); });
  box.addEventListener("dragover", e => { if (e.target.closest("#impDrop")) { e.preventDefault(); $("#impDrop").classList.add("over"); } });
  box.addEventListener("dragleave", e => { if (e.target.closest("#impDrop")) $("#impDrop").classList.remove("over"); });
  box.addEventListener("drop", e => { if (!e.target.closest("#impDrop")) return; e.preventDefault(); $("#impDrop").classList.remove("over"); show(e.dataTransfer.files[0]); });
  $("#impGo").addEventListener("click", async () => {
    if (!prepared) return;
    const b = $("#impGo");
    b.disabled = true; b.textContent = "Import en cours…";
    try {
      const d = await api("import", {}, { entity: "contacts", rows: prepared.rows.filter(r => !r.block).map(r => r.data) });
      if (!d.errors.length) { closeModal(); toast(`${num(d.imported)} lead(s) importé(s)`); afterMutation("contacts"); return; }
      $("#impOut").innerHTML = `<div class="imp-sum"><span class="pill good">${num(d.imported)} importé(s)</span><span class="pill bad">${num(d.errors.length)} refusé(s)</span></div><p class="small" style="margin:8px 0">Ces lignes n'ont pas été importées. Corrigez-les dans le fichier, puis importez uniquement ces lignes.</p><div class="dl-list">${d.errors.slice(0, 20).map(x => `<div class="dl-row">${ic("x", "sm")}<span>Ligne ${x.line} : ${esc(x.message)}</span></div>`).join("")}</div>`;
      b.textContent = "Terminé"; prepared = null;
      $("[data-close]", box).textContent = "Fermer";
      afterMutation("contacts");
    } catch (err) { toast(err.message, "err"); b.disabled = false; b.textContent = "Réessayer l'import"; }
  });
}
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
  const opt = ([o, l]) => `<option value="${esc(o)}"${o === v ? " selected" : ""}>${esc(l)}</option>`;
  const head = f.req ? (v ? "" : '<option value="" disabled selected>— Choisir —</option>') : `<option value="">${esc(f.empty || "—")}</option>`;
  return head + Object.entries(opts).map(opt).join("");
}
/** Canal : d'abord la catégorie, puis les canaux de cette catégorie seulement. */
function channelOptions(cat, v) {
  const list = cat ? channelsIn(cat) : [];
  let html = `<option value=""${v ? "" : " selected"}>${cat ? "Canal…" : "← d'abord la catégorie"}</option>`;
  if (v && !list.some(c => c.key === v)) html += `<option value="${esc(v)}" selected>${esc(L("sources", v))}</option>`;
  return html + list.map(c => `<option value="${esc(c.key)}"${c.key === v ? " selected" : ""}>${esc(c.label)}</option>`).join("");
}
function channelPicker(id, k, f, v) {
  const cat = catOf(v);
  return `<div class="cascade"><select data-cat-for="${k}" aria-label="Catégorie de canal"><option value="">Catégorie…</option>${TX().categories.map(c => `<option value="${c.key}"${c.key === cat ? " selected" : ""}>${esc(c.label)}</option>`).join("")}</select>`
    + `<select id="${id}" name="${k}"${f.req ? " required" : ""}${cat || v ? "" : " disabled"} aria-label="Canal">${channelOptions(cat, v)}</select></div>`;
}
/** Support : ceux du canal choisi d'abord, puis tous les autres. */
function supportOptions(ch, v, f) {
  const own = ch ? supportsOfChannel(ch) : [];
  const others = SUPPORTS().map(x => x.key).filter(k => !own.includes(k));
  const opt = k => `<option value="${esc(k)}"${k === v ? " selected" : ""}>${esc(L("supports", k))}</option>`;
  let html = `<option value="">${esc(f.empty || "—")}</option>`;
  if (v && !supportOf(v)) html += opt(v);
  if (own.length) html += `<optgroup label="Supports de ${esc(L("sources", ch))}">${own.map(opt).join("")}</optgroup>`;
  if (others.length) html += `<optgroup label="${own.length ? "Autres supports" : "Tous les supports"}">${others.map(opt).join("")}</optgroup>`;
  return html;
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
    case "enum": case "ref":
      if (f.opts === "sources") { input = channelPicker(id, k, f, v == null ? "" : String(v)); break; }
      if (f.opts === "supports") { input = `<select id="${id}" name="${k}">${supportOptions(SC(entity).fields.source ? vals.source : "email", v == null ? "" : String(v), f)}</select>`; break; }
      input = `<select id="${id}" name="${k}"${req}>${optionsFor(entity, k, f, v == null ? "" : String(v), vals)}</select>`;
      break;
    default: input = `<input type="text" id="${id}" name="${k}" value="${esc(val)}"${req}>`;
  }
  const when = f.when ? ` data-when="${f.when[0]}:${f.when[1]}"` : (f.opts === "supports" && SC(entity).fields.source ? ' data-needs="source"' : "");
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
  // Divulgation progressive : le support n'apparaît qu'une fois le canal choisi.
  for (const el of $$("[data-needs]", form)) el.hidden = !form.elements[el.dataset.needs] || !form.elements[el.dataset.needs].value;
}
const FORM_TITLES = { contacts: "lead / contact", inquiries: "question / inquiry", projects: "projet", posts: "action de communication", social_stats: "relevé de performances", campaigns: "campagne publicitaire", ad_results: "résultats publicitaires", pages: "page", web_stats: "statistiques de page", emails: "campagne email", funnel_entries: "chiffres de funnel" };

function openForm(entity, row = null, preset = {}) {
  if (entity === "funnels") return openFunnelEditor(row ? row.id : 0);
  if (entity === "posts") return openActionWizard(row, preset);
  if (entity === "funnel_entries" && !row) {
    const f = REF("funnels").find(x => +x.id === +preset.funnel_id && manualInputs(x).length) || REF("funnels").find(x => manualInputs(x).length);
    if (!f) return toast("Rien à saisir : aucun funnel n'a d'étape en saisie manuelle ni de KPI manuel.");
    return api("funnel", { id: f.id }).then(d => openFunnelEntry(d.funnel, d.entries)).catch(err => toast(err.message, "err"));
  }
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
    const t = e.target;
    if (t.dataset.catFor) {
      const sel = form.elements[t.dataset.catFor];
      sel.innerHTML = channelOptions(t.value, "");
      sel.disabled = !t.value;
      if (t.value) sel.focus();
    }
    if ((t.dataset.catFor || t.name === "source") && form.elements.support && fields.source) {
      form.elements.support.innerHTML = supportOptions(form.elements.source.value, form.elements.support.value, fields.support);
    }
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
      toast(row ? "Modifications enregistrées" : "Ajouté");
      if (again) {
        const keep = {};
        for (const k of ["date", "source", "support", "platform", "campaign_id", "page_id", "funnel_id", "type", "assigned_to"]) if (k in data) keep[k] = data[k];
        afterMutation(entity);
        openForm(entity, null, keep);
      } else {
        closeModal();
        afterMutation(entity);
      }
    } catch (err) { $("#mErr").textContent = err.message; }
  });
}

/* ---------- Funnel Studio : dessiner un parcours (entrées, étapes, aiguillages, circuits qui se rejoignent) */
/** Canaux d'une étape affichés en petites étiquettes. */
const chanChips = keys => (keys || []).map(k => `<span class="chan${channelOf(k) ? "" : " gone"}">${catIc(catOf(k) || "other")}${esc(L("sources", k))}</span>`).join("");
/** Sources de chiffres calculées à partir des canaux de l'étape (il faut donc au moins un canal). */
const needsCh = src => /^(stage_|social_)/.test(src || "");
const splitKeys = v => Array.isArray(v) ? v.map(String) : String(v || "").split(",").filter(Boolean);
/** Groupes de la liste « Source des chiffres » d'une étape. */
const STAGE_SRC_GROUPS = [
  [k => k === "manual", "Saisie"],
  [k => k === "parents_sum", "Aiguillage"],
  [k => /^stage_/.test(k) && !/conversations|messages/.test(k), "Canaux de l'étape"],
  [k => /^stage_(conversations|prospects_messages)/.test(k), "Messages Messenger / Instagram (canaux de l'étape)"],
  [k => /^social_/.test(k), "Canaux de l'étape · relevés des comptes"],
  [k => /^ads_/.test(k), "Campagnes publicitaires associées"],
  [k => /^page_/.test(k), "Pages associées"],
  [k => /^email_/.test(k), "Emails associés"],
  [k => /^contacts/.test(k), "Leads liés au funnel"],
];
const srcHelp = (s, root) => s === "parents_sum" ? (root ? "Cette étape n'a pas d'étape précédente : choisissez une autre source, ou cochez une étape dans « Vient de »." : "Additionne les prospects des étapes qui mènent ici : idéal pour un aiguillage qui regroupe plusieurs entrées.")
  : needsCh(s) ? "Calculé à partir des canaux cochés plus bas." : s === "manual" ? "Saisie à la main dans « Chiffres saisis à la main » (page de suivi du funnel)."
  : /^ads_/.test(s) ? "Calculé à partir des campagnes associées (onglet « Funnel & associations »)." : /^page_/.test(s) ? "Calculé à partir des pages associées (onglet « Funnel & associations »)."
  : /^email_/.test(s) ? "Calculé à partir des emails associés (onglet « Funnel & associations »)." : "Leads rattachés à ce funnel, à ses campagnes, pages, emails ou aux canaux de ses étapes.";

const FB_TEMPLATES = [
  { key: "simple", name: "Parcours simple", text: "Une entrée, des étapes en ligne droite. Idéal pour suivre une campagne.", ic: ["trend", "violet"], nodes: [
    ["a", "Publicité", "entry", "ads_clicks", [], []], ["b", "Landing page", "step", "page_visits", [], ["a"]],
    ["c", "Inscription", "step", "page_registrations", [], ["b"]], ["d", "Investissement", "goal", "manual", [], ["c"]]] },
  { key: "multi", name: "Plusieurs points d'entrée", text: "Facebook, Instagram et le site apportent des leads qui se rejoignent, puis se qualifient.", ic: ["merge", "teal"], nodes: [
    ["a", "Facebook", "entry", "stage_leads", ["facebook"], []], ["b", "Instagram", "entry", "stage_leads", ["instagram"], []],
    ["c", "Site web", "entry", "stage_leads", ["site", "landing"], []], ["d", "Leads reçus", "step", "parents_sum", [], ["a", "b", "c"]],
    ["e", "Leads qualifiés", "step", "contacts_qualified", [], ["d"]], ["f", "Convertis", "goal", "contacts_converted", [], ["e"]]] },
  { key: "qualif", name: "Qualifié / sinon", text: "Les leads qualifiés vont au commercial ; les autres passent par une relance avant de revenir.", ic: ["branch", "blue"], nodes: [
    ["a", "Formulaire reçu", "entry", "contacts", [], []], ["b", "Qualification", "split", "parents_sum", [], ["a"]],
    ["c", "Appel commercial", "step", "contacts_qualified", [], ["b"], "Qualifié"], ["d", "Relance (nurturing)", "step", "contacts", [], ["b"], "Sinon", "non_qualifie"],
    ["e", "Email de relance cliqué", "step", "email_clicks", [], ["d"]], ["f", "Investissement", "goal", "contacts_converted", [], ["c", "e"]]] },
  { key: "profils", name: "Investisseurs / porteurs", text: "Publicité et messages alimentent les leads, puis chaque profil suit son propre circuit.", ic: ["users", "pink"], nodes: [
    ["a", "Publicité Meta", "entry", "ads_leads", [], []], ["b", "Messages Messenger / Instagram", "entry", "stage_conversations", ["messenger", "instagram"], []],
    ["c", "Leads", "split", "parents_sum", [], ["a", "b"]],
    ["d", "Investisseurs qualifiés", "step", "contacts_qualified", [], ["c"], "Investisseur", "investisseur"], ["e", "Porteurs qualifiés", "step", "contacts_qualified", [], ["c"], "Porteur", "porteur"],
    ["f", "Investissement", "goal", "contacts_converted", [], ["d"], "", "investisseur"], ["g", "Projet déposé", "goal", "contacts_converted", [], ["e"], "", "porteur"]] },
  { key: "messages", name: "Messages → prospects", text: "Conversations Messenger et Instagram, les plus chaudes, puis transformées en prospects.", ic: ["message", "teal"], nodes: [
    ["a", "Messenger", "entry", "stage_conversations", ["messenger"], []], ["b", "Instagram Direct", "entry", "stage_conversations", ["instagram"], []],
    ["c", "Conversations chaudes", "step", "stage_conversations_hot", ["messenger", "instagram"], ["a", "b"]],
    ["d", "Prospects créés", "step", "stage_prospects_messages", ["messenger", "instagram"], ["c"]], ["e", "Convertis", "goal", "contacts_converted", [], ["d"]]] },
  { key: "blank", name: "Partir de zéro", text: "Un point d'entrée, et vous dessinez la suite comme vous voulez.", ic: ["sparkles", "amber"], nodes: [
    ["a", "Point d'entrée", "entry", "manual", [], []]] },
];
const tplNodes = t => t.nodes.map(([key, name, kind, source, channels, parents, branch = "", segment = ""]) => ({ key, id: 0, name, kind, source, channels: channels.filter(k => channelOf(k)), supports: [], kpis: kpiDefaults(source), target: 0, tags: "", branch, segment, parents: [...parents] }));

/** Ancien point d'appel (formulaires, menu « Nouveau ») : ouvre le Funnel Studio. */
function openFunnelEditor(id) { location.hash = "#funnels/edit/" + (+id || "new"); }

function fbState(f) {
  const funnel = {};
  for (const k of Object.keys(SC("funnels").fields)) funnel[k] = f[k] == null ? "" : f[k];
  return { param: String(f.id), id: +f.id, funnel, links: { campaigns: [...f.links.campaigns], pages: [...f.links.pages], emails: [...f.links.emails] },
    nodes: f.stages.map(s => ({ key: "id" + s.id, id: +s.id, name: s.name, kind: s.kind || "step", source: s.source, channels: splitKeys(s.channels), supports: splitKeys(s.supports), kpis: parseKpis(s.kpis), target: +s.target || 0, tags: s.tags || "",
      branch: s.branch || "", segment: s.segment || "", parents: splitKeys(s.parents).map(p => "id" + p) })),
    sel: null, tab: "node", dirty: false, seq: 0, zoom: 1, link: null };
}
const fbNode = k => S.fb.nodes.find(n => n.key === k);
const fbKids = k => S.fb.nodes.filter(n => n.parents.includes(k));
/** Étapes qui suivent (directement ou non) une étape : elles ne peuvent pas la précéder. */
function fbDesc(k) {
  const out = new Set(), todo = [k];
  while (todo.length) { const x = todo.pop(); for (const n of S.fb.nodes) if (n.parents.includes(x) && !out.has(n.key)) { out.add(n.key); todo.push(n.key); } }
  return out;
}
const fbLinkTargets = from => { const ok = new Set(); for (const n of S.fb.nodes) if (n.key !== from && !n.parents.includes(from) && !fbDesc(n.key).has(from)) ok.add(n.key); return ok; };
/** Une étape sans étape précédente est un point d'entrée ; une étape qui en a une n'en est plus un. */
const fbFixKinds = () => S.fb.nodes.forEach(n => { if (!n.parents.length) n.kind = "entry"; else if (n.kind === "entry") n.kind = "step"; });
function fbCircuits(nodes) {
  const memo = new Map();
  const cnt = k => {
    if (memo.has(k)) return memo.get(k);
    memo.set(k, 0);
    const c = nodes.filter(n => n.parents.includes(k));
    const v = c.length ? c.reduce((s, x) => s + cnt(x.key), 0) : 1;
    memo.set(k, v);
    return v;
  };
  return nodes.filter(n => !n.parents.length).reduce((s, n) => s + cnt(n.key), 0);
}
function fbDirty() { S.fb.dirty = true; const b = $("#fbSave"); if (b) b.hidden = false; }
const fbNew = (o = {}) => Object.assign({ key: "n" + ++S.fb.seq, id: 0, name: "Nouvelle étape", kind: "step", source: "manual", channels: [], supports: [], kpis: [], target: 0, tags: "", branch: "", segment: "", parents: [] }, o);

function fbTemplatesPage() {
  return `<div class="fb-tpl-h"><a class="icon-btn round" href="#funnels/list" aria-label="Retour aux funnels">${ic("chev-l")}</a><div><h2>Créer un funnel</h2><p class="muted">Choisissez un point de départ : vous pourrez tout changer ensuite (étapes, branches, sources des chiffres, canaux).</p></div></div>
    <div class="fb-tpls">${FB_TEMPLATES.map(t => `<div class="fb-tpl" role="button" tabindex="0" data-tpl="${t.key}">${flowHtml(tplNodes(t), { mini: true })}<div class="fb-tpl-t">${tile(t.ic[0], t.ic[1], "sm")}<span><b>${esc(t.name)}</b><small>${esc(t.text)}</small></span></div></div>`).join("")}</div>`;
}
function fbTplMount() {
  const box = $(".fb-tpls");
  if (!box) return;
  const pick = el => {
    const t = FB_TEMPLATES.find(x => x.key === el.dataset.tpl);
    const nodes = tplNodes(t);
    S.fb = { param: "new", id: 0, funnel: Object.assign(defaults("funnels"), { name: "" }), links: { campaigns: [], pages: [], emails: [] }, nodes, sel: null, tab: "node", dirty: true, seq: 0, zoom: 1, link: null };
    V.innerHTML = fbPage();
    fbMount();
    $("#fbName").focus();
  };
  box.addEventListener("click", e => { const el = e.target.closest("[data-tpl]"); if (el) pick(el); });
  box.addEventListener("keydown", e => { const el = e.target.closest("[data-tpl]"); if (el && (e.key === "Enter" || e.key === " ")) { e.preventDefault(); pick(el); } });
}

function fbPage() {
  const fb = S.fb;
  const leg = ["entry", "step", "split", "goal"].map(k => `<span><i class="k-${k}"></i>${esc(KIND_META[k][2])}</span>`).join("");
  return `<div id="fb" class="fb">
    <div class="fb-bar">
      <a class="icon-btn round" href="${fb.id ? "#funnels/stages/" + fb.id : "#funnels/list"}" aria-label="Retour">${ic("chev-l")}</a>
      <div class="fb-title"><small>${ic("branch", "xs")} Funnel Studio · ${fb.id ? "modifier le parcours" : "nouveau parcours"}</small><input id="fbName" type="text" value="${esc(fb.funnel.name)}" maxlength="80" placeholder="Nommez ce funnel (ex. Acquisition investisseurs)" aria-label="Nom du funnel"></div>
      <span class="fb-count" id="fbCount"></span>
      <button type="button" class="btn" data-fbact="cancel">Annuler</button>
      <button type="button" class="btn pri" data-fbact="save">${ic("check", "sm")} ${fb.id ? "Enregistrer" : "Créer le funnel"}</button>
    </div>
    <div class="fb-grid">
      <section class="fb-board">
        <div class="fb-tools">
          <button type="button" class="btn sm" data-fbact="entry">${ic("play-c", "sm")} Ajouter une entrée</button>
          <span class="fl-legend">${leg}</span><span class="grow"></span>
          <span class="fb-zoom"><button type="button" class="icon-btn" data-fbact="zoom-out" aria-label="Dézoomer" title="Dézoomer">${ic("minus", "sm")}</button><span id="fbZ">100 %</span><button type="button" class="icon-btn" data-fbact="zoom-in" aria-label="Zoomer" title="Zoomer">${ic("plus", "sm")}</button><button type="button" class="icon-btn" data-fbact="fit" aria-label="Ajuster à l'écran" title="Ajuster à l'écran">${ic("maximize", "sm")}</button></span>
        </div>
        <div class="fb-linkbar" id="fbLink" hidden></div>
        <div class="fb-canvas" id="fbCanvas"></div>
        <p class="fb-tip">${ic("info", "xs")} Le <b>+</b> d'une étape ajoute la suite ; glissez-le jusqu'à une autre étape pour relier deux circuits. Cliquez sur une étape pour la régler. <kbd>Suppr</kbd> retire l'étape choisie, <kbd>Échap</kbd> annule.</p>
      </section>
      <aside class="fb-insp card" id="fbInsp" aria-label="Réglages"></aside>
    </div>
    <div class="savebar" id="fbSave"${fb.dirty ? "" : " hidden"}><span>Parcours modifié, pas encore enregistré</span><span class="grow"></span><button type="button" class="btn" data-fbact="cancel">Annuler</button><button type="button" class="btn pri" data-fbact="save">Enregistrer</button></div>
  </div>`;
}

function fbDrawCanvas() {
  const fb = S.fb, c = $("#fbCanvas");
  if (!c) return;
  const old = $(".fl-scroll", c), keep = old ? [old.scrollLeft, old.scrollTop] : null;
  c.innerHTML = flowHtml(fb.nodes, { edit: true, sel: fb.sel, zoom: fb.zoom, linkFrom: fb.link, linkOk: fb.link ? fbLinkTargets(fb.link) : null });
  drawFlows();
  if (keep) { const s = $(".fl-scroll", c); s.scrollLeft = keep[0]; s.scrollTop = keep[1]; }
  $("#fbZ").textContent = Math.round(fb.zoom * 100) + " %";
  const n = fbCircuits(fb.nodes);
  $("#fbCount").textContent = `${fb.nodes.length} étape${fb.nodes.length > 1 ? "s" : ""} · ${n} circuit${n > 1 ? "s" : ""}`;
  const lb = $("#fbLink"), from = fb.link && fbNode(fb.link);
  lb.hidden = !from;
  if (from) lb.innerHTML = `${ic("link", "sm")}<span>Cliquez sur l'étape qui doit suivre « ${esc(from.name)} » (les étapes possibles sont en pointillés).</span><span class="grow"></span><button type="button" class="btn sm" data-fbact="unlink">Annuler</button>`;
}
/**
 * Nom ou libellé de branche modifié : on met à jour l'étape et ses liens sur place, sans redessiner
 * tout le parcours (un clic sur une autre étape juste après n'est ainsi jamais perdu).
 */
function fbPatch(n) {
  const fl = $("#fbCanvas .fl");
  if (!fl) return;
  const el = $$(".fn", fl).find(x => x.dataset.k === n.key);
  if (el) $(".fn-name", el).textContent = n.name || "Sans nom";
  const data = FL.get(fl.dataset.fl);
  if (data) data.edges.forEach(e => { if (e.to === n.key) e.label = n.branch || ""; });
  cancelAnimationFrame(fbPatch.raf);
  fbPatch.raf = requestAnimationFrame(() => drawFlow(fl));
}
function fbSelect(k, scroll = false) {
  const fb = S.fb;
  fb.sel = k;
  if (k) fb.tab = "node";
  $$("#fbCanvas .fn").forEach(x => x.classList.toggle("sel", x.dataset.k === k));
  fbDrawInsp();
  if (scroll && k && window.innerWidth <= 1100) $("#fbInsp").scrollIntoView({ behavior: "smooth", block: "start" });
}

function fbDrawInsp() {
  const fb = S.fb, el = $("#fbInsp");
  if (!el) return;
  const n = fb.sel && fbNode(fb.sel);
  const tabs = `<div class="fb-tabs" role="tablist"><button type="button" role="tab" data-fbtab="node" aria-selected="${fb.tab === "node"}" class="${fb.tab === "node" ? "on" : ""}">${ic("layers", "sm")} Étape</button><button type="button" role="tab" data-fbtab="funnel" aria-selected="${fb.tab === "funnel"}" class="${fb.tab === "funnel" ? "on" : ""}">${ic("settings", "sm")} Funnel & associations</button></div>`;
  el.innerHTML = tabs + (fb.tab === "funnel" ? fbFunnelPane() : n ? fbNodePane(n) : fbHelpPane());
}
function fbHelpPane() {
  return `<div class="fb-help">${tile("sparkles", "violet")}<b>Dessinez votre parcours</b><p class="muted small">Comme un circuit : des entrées, des étapes, des aiguillages, et des branches qui se rejoignent.</p><ul>
    <li>${tile("plus", "violet", "xs")}<span>Le <b>+</b> à droite d'une étape ajoute la suite : une étape, un aiguillage (qualifié / sinon, investisseurs / porteurs) ou l'objectif final.</span></li>
    <li>${tile("link", "amber", "xs")}<span>Glissez ce <b>+</b> jusqu'à une autre étape pour les relier : deux circuits se rejoignent.</span></li>
    <li>${tile("play-c", "green", "xs")}<span><b>Point d'entrée</b> ajoute une autre source de prospects : publicité, Instagram, messages, salon…</span></li>
    <li>${tile("edit", "blue", "xs")}<span>Cliquez sur une étape pour choisir d'où viennent ses chiffres, ses canaux, son segment et son objectif.</span></li></ul></div>`;
}
function fbSrcOpts(v, root) {
  const all = Object.entries(EN("stage_sources")).filter(([k]) => !(root && k === "parents_sum" && v !== k));
  return STAGE_SRC_GROUPS.map(([test, label]) => {
    const list = all.filter(([k]) => test(k));
    return list.length ? `<optgroup label="${esc(label)}">${list.map(([k, l]) => `<option value="${k}"${k === v ? " selected" : ""}>${esc(l)}</option>`).join("")}</optgroup>` : "";
  }).join("");
}
function fbChPicker(n) {
  const box = (k, l) => `<label><input type="checkbox" data-fbch value="${esc(k)}"${n.channels.includes(k) ? " checked" : ""}> ${esc(l)}</label>`;
  const gone = n.channels.filter(k => !channelOf(k)); // canaux retirés des Réglages mais encore cochés sur cette étape
  const groups = TX().categories.map(c => [c, channelsIn(c.key)]).filter(g => g[1].length).map(([c, list]) => {
    const k = list.filter(x => n.channels.includes(x.key)).length;
    return `<details class="ch-cat"${k ? " open" : ""}><summary>${catIc(c.key)} ${esc(c.label)}${k ? ` <span class="pill blue">${k}</span>` : ""}</summary><div class="ch-list">${list.map(x => box(x.key, x.label)).join("")}</div></details>`;
  }).join("");
  return `<div class="banner warn fb-chwarn"${needsCh(n.source) && !n.channels.length ? "" : " hidden"}>${ic("alert", "sm")}<span>Cette source compte les données de ses canaux : cochez au moins un canal.</span></div>`
    + `<div class="ch-pick fb-ch">${groups}${gone.length ? `<details class="ch-cat" open><summary>${ic("archive", "sm")} Canaux retirés</summary><div class="ch-list">${gone.map(k => box(k, L("sources", k))).join("")}</div></details>` : ""}</div>`;
}
function fbSupPicker(n) {
  const own = [...new Set(n.channels.flatMap(ch => supportsOfChannel(ch)))];
  const others = SUPPORTS().map(x => x.key).filter(k => !own.includes(k));
  const gone = n.supports.filter(k => !supportOf(k));
  const box = k => `<label><input type="checkbox" data-fbsup value="${esc(k)}"${n.supports.includes(k) ? " checked" : ""}> ${esc(L("supports", k))}</label>`;
  const nOther = others.filter(k => n.supports.includes(k)).length;
  return `<div class="ch-pick fb-ch">${own.length ? `<p class="small muted" style="margin:6px 0 2px">Supports des canaux cochés :</p><div class="ch-list fb-sup">${own.map(box).join("")}</div>` : `<p class="small muted" style="margin:6px 0">Cochez d'abord des canaux : leurs supports (Reel, carrousel, newsletter…) s'afficheront ici.</p>`}
    <details class="ch-cat"${nOther ? " open" : ""}><summary>${ic("layers", "sm")} ${own.length ? "Autres supports" : "Tous les supports"}${nOther ? ` <span class="pill blue">${nOther}</span>` : ""}</summary><div class="ch-list">${others.map(box).join("")}</div></details>
    ${gone.length ? `<details class="ch-cat" open><summary>${ic("archive", "sm")} Supports retirés</summary><div class="ch-list">${gone.map(box).join("")}</div></details>` : ""}</div>
    <small>Les actions et les leads comptés pour cette étape se limitent à ces supports. Aucun coché : tous les supports.</small>`;
}
function fbKpiEditor(n) {
  const groups = EN("stage_kpi_groups"), grpOf = EN("stage_kpi_group");
  const used = new Set(n.kpis.map(k => k.k));
  const rows = n.kpis.map((kd, i) => {
    const man = String(kd.k).startsWith("m_"), fmt = kpiFmtOf(kd);
    const unit = fmt === "pct" || fmt === "pctv" ? "%" : fmt === "money" ? cur() : "";
    return `<div class="kpi-row"><span class="kpi-l"><b>${esc(kpiLabel(kd))}</b><small>${man ? `KPI manuel · ${kd.a === "last" ? "dernière valeur saisie" : "total de la période"}` : esc(groups[grpOf[kd.k]] || "")}</small></span>
      <span class="kpi-t"><input type="number" min="0" step="any" data-fbkt="${i}" value="${kd.t || ""}" placeholder="Objectif" aria-label="Objectif de ${esc(kpiLabel(kd))}">${unit ? `<em>${esc(unit)}</em>` : ""}</span>
      <span class="kpi-mv">${i ? `<button type="button" class="icon-btn" data-fbkmv="${i}" title="Monter" aria-label="Monter ${esc(kpiLabel(kd))}">${ic("chev-u", "sm")}</button>` : ""}<button type="button" class="icon-btn del" data-fbkdel="${i}" title="Retirer" aria-label="Retirer ${esc(kpiLabel(kd))}">${ic("x", "sm")}</button></span></div>`;
  }).join("");
  const opts = Object.entries(groups).map(([g, gl]) => {
    const ks = Object.keys(EN("stage_kpis")).filter(k => grpOf[k] === g && !used.has(k));
    return ks.length ? `<optgroup label="${esc(gl)}">${ks.map(k => `<option value="${k}">${esc(L("stage_kpis", k))}</option>`).join("")}</optgroup>` : "";
  }).join("");
  return `<div class="kpi-list">${rows || '<p class="small muted" style="margin:0">Aucun KPI : la case affiche le nombre de prospects de l\'étape.</p>'}</div>
    ${n.kpis.length < 12 ? `<div class="kpi-add"><select id="fbKAdd" aria-label="Ajouter un KPI calculé"><option value="">+ Ajouter un KPI calculé…</option>${opts}</select>
      <details class="kpi-man"><summary>${ic("edit", "sm")} Créer un KPI manuel</summary><div class="kpi-man-f">
        <input type="text" id="fbKmL" maxlength="60" placeholder="Nom (ex. Rendez-vous pris)" aria-label="Nom du KPI manuel">
        <select id="fbKmF" aria-label="Type de valeur"><option value="num">Nombre</option><option value="pctv">Pourcentage</option><option value="money">Montant (${esc(cur())})</option></select>
        <select id="fbKmA" aria-label="Calcul sur la période"><option value="sum">Total de la période</option><option value="last">Dernière valeur saisie</option></select>
        <button type="button" class="btn sm" data-fbact="kman">${ic("plus", "sm")} Ajouter ce KPI</button></div>
        <small>Ses chiffres se saisissent dans le suivi du funnel, bouton « Saisir des chiffres ».</small></details></div>` : '<p class="small muted">12 KPIs au plus par étape.</p>'}
    <small>Chaque KPI s'affiche dans la case avec sa valeur sur la période choisie ; avec un objectif, il passe au vert une fois atteint.</small>`;
}
function fbNodePane(n) {
  const fb = S.fb, km = KIND_META[n.kind] || KIND_META.step, root = !n.parents.length;
  const desc = fbDesc(n.key);
  const cands = fb.nodes.filter(x => x.key !== n.key && !desc.has(x.key));
  const kinds = root
    ? `<p class="fb-note small">${ic("play-c", "sm")}<span><b>Point d'entrée</b> : aucune étape ne mène ici. Pour la placer après une autre, cochez celle-ci dans « Vient de ».</span></p>`
    : `<div class="fld"><label>Rôle dans le parcours</label><div class="fb-kinds">${["step", "split", "goal"].map(k => `<button type="button" data-fbkind="${k}" class="${n.kind === k ? "on" : ""}" aria-pressed="${n.kind === k}">${tile(KIND_META[k][0], KIND_META[k][1], "xs")}${esc(KIND_META[k][2])}</button>`).join("")}</div></div>`;
  return `<div class="fb-head">${tile(km[0], km[1])}<div><small>${esc(km[2])}</small><b id="fbHeadName">${esc(n.name || "Sans nom")}</b></div></div>${kinds}
    <div class="fld"><label for="fbN">Nom de l'étape</label><input id="fbN" type="text" data-fbf="name" value="${esc(n.name)}" maxlength="80" placeholder="ex. Landing page"></div>
    ${root ? "" : `<div class="fld"><label for="fbB">Libellé sur la flèche <span class="muted">(facultatif)</span></label><input id="fbB" type="text" data-fbf="branch" value="${esc(n.branch)}" maxlength="40" placeholder="ex. Qualifié, Sinon, Investisseur"><small>S'affiche sur la flèche qui arrive ici : pour nommer les branches d'un aiguillage.</small></div>`}
    <div class="fld"><label>Vient de</label><div class="linkbox fb-parents">${cands.length ? cands.map(x => `<label><input type="checkbox" data-fbp="${esc(x.key)}"${n.parents.includes(x.key) ? " checked" : ""}> ${esc(x.name || "Sans nom")}</label>`).join("") : '<span class="muted small">Aucune autre étape possible.</span>'}</div><small>Cochez plusieurs étapes quand des circuits se rejoignent ici. Les étapes qui suivent celle-ci ne sont pas proposées (le parcours tournerait en rond).</small></div>
    <div class="fld"><label for="fbS">Source des chiffres</label><select id="fbS" data-fbf="source">${fbSrcOpts(n.source, root)}</select><small>${esc(srcHelp(n.source, root))}</small></div>
    ${LEAD_SRC(n.source) ? `<div class="fld"><label for="fbG">Leads comptés</label><select id="fbG" data-fbf="segment">${Object.entries(EN("stage_segments")).map(([k, l]) => `<option value="${esc(k)}"${k === n.segment ? " selected" : ""}>${esc(l)}</option>`).join("")}</select><small>Pour une branche « Investisseurs » ou « Qualifié » : seuls ces leads sont comptés.</small></div>` : ""}
    <div class="fld"><label>Canaux de communication</label>${fbChPicker(n)}</div>
    <div class="fld"><label>Supports de communication</label><div id="fbSupBox">${fbSupPicker(n)}</div></div>
    <div class="fld"><label>KPIs affichés dans la case</label>${fbKpiEditor(n)}</div>
    <div class="form fb-2"><div class="fld"><label for="fbT">Objectif</label><input id="fbT" type="number" min="0" inputmode="numeric" data-fbf="target" value="${n.target || ""}" placeholder="—"></div><div class="fld"><label for="fbTg">Tags</label><input id="fbTg" type="text" data-fbf="tags" value="${esc(n.tags)}" placeholder="ex. meta, retargeting"></div></div>
    <div class="fb-acts"><button type="button" class="btn sm pri" data-fbact="add">${ic("plus", "sm")} Ajouter la suite</button><button type="button" class="btn sm" data-fbact="link">${ic("link", "sm")} Relier à…</button><button type="button" class="btn sm del" data-fbact="del">${ic("trash", "sm")} Supprimer</button></div>`;
}
function fbFunnelPane() {
  const fb = S.fb;
  const fields = Object.entries(SC("funnels").fields).filter(([k]) => k !== "name").map(([k, fd]) => fieldHtml("funnels", k, fd, fb.funnel[k], fb.funnel)).join("");
  const box = (t, label, list) => `<div class="fld"><label>${label}</label><div class="linkbox">${list.length ? list.map(x => `<label><input type="checkbox" data-fbl="${t}" value="${x.id}"${fb.links[t].includes(+x.id) ? " checked" : ""}> ${esc(x.name)}</label>`).join("") : '<span class="muted small">Rien à associer pour l\'instant.</span>'}</div></div>`;
  return `<form id="fbForm" class="form fb-form" novalidate>${fields}</form><h3>Associations</h3><p class="small muted" style="margin-top:-4px">Les étapes dont la source est « campagnes », « pages » ou « emails associés » comptent ce que vous cochez ici.</p>
    <div class="form fb-form">${box("campaigns", "Campagnes publicitaires", REF("campaigns"))}${box("pages", "Landing pages / site", REF("pages"))}${box("emails", "Campagnes email", REF("emails"))}</div>`;
}

/* ---------- actions du constructeur */
function fbAddAfter(key, what) {
  const fb = S.fb, n = fbNode(key);
  if (!n) return;
  if (what === "link") return fbStartLink(key);
  const had = fbKids(key).length;
  let added = [];
  if (what === "next") added = [fbNew({ parents: [key] })];
  if (what === "goal") added = [fbNew({ name: "Objectif atteint", kind: "goal", source: "contacts_converted", kpis: kpiDefaults("contacts_converted"), parents: [key] })];
  if (what === "split-qual") added = [fbNew({ name: "Leads qualifiés", source: "contacts_qualified", kpis: kpiDefaults("contacts_qualified"), branch: "Qualifié", parents: [key] }), fbNew({ name: "Relance (nurturing)", source: "contacts", segment: "non_qualifie", branch: "Sinon", parents: [key] })];
  if (what === "split-prof") added = [fbNew({ name: "Investisseurs", source: "contacts", segment: "investisseur", branch: "Investisseur", parents: [key] }), fbNew({ name: "Porteurs de projets", source: "contacts", segment: "porteur", branch: "Porteur", parents: [key] })];
  if (what === "split-free") added = [fbNew({ name: "Étape A", branch: "Branche A", parents: [key] }), fbNew({ name: "Étape B", branch: "Branche B", parents: [key] })];
  if (!added.length) return;
  // Une étape qui part dans plusieurs directions devient un aiguillage.
  if (n.kind === "goal") n.kind = "step";
  if (had + added.length > 1 && n.kind === "step") n.kind = "split";
  fb.nodes.push(...added);
  fbFixKinds();
  fbDirty();
  fb.sel = added[0].key;
  fb.tab = "node";
  fbDrawCanvas();
  fbDrawInsp();
  const el = $(`#fbCanvas .fn[data-k="${added[0].key}"]`);
  if (el) el.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
  const name = $("#fbN");
  if (name && window.innerWidth > 1100) { name.focus(); name.select(); }
}
function fbStartLink(key) {
  const fb = S.fb;
  if (!fbLinkTargets(key).size) return toast("Aucune étape ne peut suivre celle-ci : toutes la précèdent déjà, ou y sont déjà reliées.", "err");
  fb.link = key;
  fbDrawCanvas();
}
function fbTryLink(from, to) {
  const fb = S.fb, a = fbNode(from), b = fbNode(to);
  if (!a || !b || from === to) return;
  if (b.parents.includes(from)) { toast(`« ${b.name} » suit déjà « ${a.name} ».`); return; }
  if (fbDesc(to).has(from)) { toast(`Impossible : « ${b.name} » mène déjà à « ${a.name} », le parcours tournerait en rond.`, "err"); return; }
  b.parents.push(from);
  if (b.source === "manual" && b.parents.length > 1 && !b.id) b.source = "parents_sum";
  fbFixKinds();
  fb.link = null;
  fbDirty();
  fb.sel = to;
  fb.tab = "node";
  fbDrawCanvas();
  fbDrawInsp();
  toast(`« ${a.name} » mène maintenant à « ${b.name} »`);
}
async function fbDelete(key) {
  const fb = S.fb, n = fbNode(key);
  if (!n) return;
  if (fb.nodes.length < 2) return toast("Un funnel garde au moins une étape.", "err");
  const kids = fbKids(key);
  const ok = await askDialog({ icon: ["trash", "pink"], title: `Supprimer l'étape « ${n.name || "sans nom"} » ?`,
    text: (kids.length ? "Les étapes qui la suivent seront reliées à celles qui la précèdent. " : "") + (n.id ? "Ses chiffres saisis à la main seront supprimés à l'enregistrement du funnel." : "Elle n'est pas encore enregistrée."),
    ok: "Supprimer l'étape", cancel: "Garder l'étape", danger: true });
  if (!ok || S.fb !== fb) return;
  fb.nodes = fb.nodes.filter(x => x !== n);
  for (const x of fb.nodes) if (x.parents.includes(key)) x.parents = [...new Set(x.parents.filter(p => p !== key).concat(n.parents))];
  fbFixKinds();
  fb.sel = null;
  fbDirty();
  fbDrawCanvas();
  fbDrawInsp();
}
function fbCloseMenu() { const m = $(".fb-menu"); if (m) m.remove(); }
function fbMenu(anchor, key) {
  fbCloseMenu();
  const n = fbNode(key);
  if (!n) return;
  const items = [["next", "plus", "violet", "Étape suivante", "Une étape après celle-ci"],
    ["split-qual", "branch", "blue", "Aiguillage qualifié / sinon", "Deux branches selon la qualification du lead"],
    ["split-prof", "users", "pink", "Aiguillage investisseurs / porteurs", "Une branche par profil"],
    ["split-free", "branch", "teal", "Aiguillage libre", "Deux branches à nommer vous-même"],
    ["goal", "flag", "orange", "Objectif final", "Fin du circuit : investissement, projet déposé…"],
    ["link", "link", "amber", "Relier à une étape existante", "Pour que deux circuits se rejoignent"]];
  const m = document.createElement("div");
  m.className = "fb-menu";
  m.dataset.at = Date.now();
  m.setAttribute("role", "menu");
  m.innerHTML = `<p class="grp-h">Après « ${esc(n.name || "cette étape")} »</p>` + items.map(([k, i, t, l, s]) => `<button type="button" role="menuitem" data-fbadd="${k}">${tile(i, t, "sm")}<span><b>${esc(l)}</b><small>${esc(s)}</small></span></button>`).join("");
  document.body.append(m);
  const r = anchor.getBoundingClientRect();
  m.style.left = Math.max(8, Math.min(r.left, innerWidth - m.offsetWidth - 8)) + "px";
  m.style.top = (r.bottom + 6 + m.offsetHeight > innerHeight - 8 ? Math.max(8, r.top - m.offsetHeight - 6) : r.bottom + 6) + "px";
  m.addEventListener("click", e => { const b = e.target.closest("[data-fbadd]"); if (b) { fbCloseMenu(); fbAddAfter(key, b.dataset.fbadd); } });
  m.addEventListener("keydown", e => {
    const bs = $$("button", m), i = bs.indexOf(document.activeElement);
    if (e.key === "ArrowDown" || e.key === "ArrowUp") { e.preventDefault(); bs[(i + (e.key === "ArrowDown" ? 1 : bs.length - 1)) % bs.length].focus(); }
    if (e.key === "Escape") { fbCloseMenu(); anchor.focus(); }
  });
  $("button", m).focus();
}
document.addEventListener("pointerdown", e => { if (!e.target.closest(".fb-menu, [data-fa], [data-fbact=add]")) fbCloseMenu(); });
window.addEventListener("resize", fbCloseMenu);
// La page défile : le menu ne serait plus à côté de son « + » (le défilement causé par son ouverture ne compte pas).
document.addEventListener("scroll", e => { const m = $(".fb-menu"); if (m && e.target === document && Date.now() - +m.dataset.at > 300) fbCloseMenu(); }, true);

async function fbSave() {
  const fb = S.fb;
  fb.funnel.name = $("#fbName").value.trim();
  if (!fb.funnel.name) { $("#fbName").focus(); return toast("Donnez un nom au funnel (en haut de la page).", "err"); }
  const fail = (n, m) => { fb.sel = n.key; fb.tab = "node"; fbDrawCanvas(); fbDrawInsp(); toast(m, "err"); };
  for (const n of fb.nodes) {
    if (!n.name.trim()) return fail(n, "Une étape n'a pas de nom : donnez-lui un nom.");
    if (needsCh(n.source) && !n.channels.length) return fail(n, `« ${n.name} » : ses chiffres viennent de ses canaux, mais aucun canal n'est coché.`);
    if (n.source === "parents_sum" && !n.parents.length) return fail(n, `« ${n.name} » additionne les étapes précédentes, mais aucune ne mène ici : choisissez une autre source.`);
  }
  const order = flowLayout(fb.nodes).flat();
  const btns = $$("[data-fbact=save]");
  btns.forEach(b => { b.disabled = true; });
  try {
    const d = await api("funnel_save", {}, { id: fb.id, funnel: fb.funnel, links: fb.links,
      stages: order.map(n => ({ key: n.key, id: n.id, name: n.name.trim(), kind: n.kind, source: n.source, channels: n.channels, supports: n.supports, kpis: n.kpis, target: +n.target || 0, tags: n.tags, branch: n.branch, segment: n.segment, parents: n.parents })) });
    fb.dirty = false;
    S.fb = null;
    S.cache.clear();
    const r = await api("refs");
    S.boot.refs = r.refs;
    S.boot.enums = r.enums;
    toast(fb.id ? "Parcours enregistré" : "Funnel créé : voici son suivi");
    location.hash = "#funnels/stages/" + d.funnel.id;
  } catch (err) {
    toast(err.message, "err");
    btns.forEach(b => { b.disabled = false; });
  }
}

function fbMount() {
  const root = $("#fb");
  if (!root) return;
  $("#title").textContent = "Funnel Studio";
  document.title = "Funnel Studio — Marketing Dashboard";
  // Petit écran : le parcours tient dans la largeur au premier affichage.
  fbDrawCanvas();
  fbDrawInsp();
  const fitNow = () => {
    const st = $("#fbCanvas .fl-stage"), sc = $("#fbCanvas .fl-scroll");
    if (!st || !sc) return;
    S.fb.zoom = Math.max(.4, Math.min(1, Math.floor((sc.clientWidth - 16) / st.offsetWidth * 20) / 20));
    fbDrawCanvas();
  };
  if (window.innerWidth <= 700) fitNow();

  root.addEventListener("click", async e => {
    const fb = S.fb, t = e.target;
    if (!fb) return;
    const port = t.closest("[data-fa]");
    if (port) { e.stopPropagation(); if (Date.now() - (fb.dragEnd || 0) < 400) return; if (fb.link) return fbTryLink(fb.link, port.dataset.fa); return fbMenu(port, port.dataset.fa); }
    const act = t.closest("[data-fbact]");
    if (act) {
      const a = act.dataset.fbact, n = fb.sel && fbNode(fb.sel);
      if (a === "save") return fbSave();
      if (a === "cancel") {
        if (fb.dirty && !(await askDialog({ icon: ["alert", "amber"], title: "Abandonner les changements ?", text: "Les étapes et réglages modifiés depuis le dernier enregistrement seront perdus.", ok: "Abandonner", cancel: "Continuer à modifier", danger: true }))) return;
        S.fb = null;
        location.hash = fb.id ? "#funnels/stages/" + fb.id : "#funnels/list";
        return;
      }
      if (a === "entry") {
        const x = fbNew({ name: "Nouveau point d'entrée", kind: "entry" });
        fb.nodes.push(x);
        fbDirty(); fb.sel = x.key; fb.tab = "node"; fbDrawCanvas(); fbDrawInsp();
        const el = $(`#fbCanvas .fn[data-k="${x.key}"]`);
        if (el) el.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
        return;
      }
      if (a === "zoom-in" || a === "zoom-out") { fb.zoom = Math.max(.4, Math.min(1.5, Math.round((fb.zoom + (a === "zoom-in" ? .1 : -.1)) * 10) / 10)); return fbDrawCanvas(); }
      if (a === "fit") return fitNow();
      if (a === "unlink") { fb.link = null; return fbDrawCanvas(); }
      if (!n) return;
      if (a === "add") return fbMenu(act, n.key);
      if (a === "link") return fbStartLink(n.key);
      if (a === "del") return fbDelete(n.key);
      if (a === "kman") {
        const l = $("#fbKmL").value.trim();
        if (!l) { $("#fbKmL").focus(); return toast("Donnez un nom au KPI manuel (ex. Rendez-vous pris).", "err"); }
        let k = "m_" + slugify(l).slice(0, 30), i = 2;
        while (n.kpis.some(x => x.k === k)) k = "m_" + slugify(l).slice(0, 28) + "_" + i++;
        n.kpis.push({ k, l, f: $("#fbKmF").value, a: $("#fbKmA").value, t: 0 });
        fbDirty(); fbDrawCanvas(); fbDrawInsp();
        return toast(`KPI « ${l} » ajouté : saisissez ses chiffres dans le suivi du funnel`);
      }
      return;
    }
    const kdel = t.closest("[data-fbkdel]"), kmv = t.closest("[data-fbkmv]");
    if ((kdel || kmv) && fb.sel) {
      const n = fbNode(fb.sel);
      if (kmv) { const i = +kmv.dataset.fbkmv; [n.kpis[i - 1], n.kpis[i]] = [n.kpis[i], n.kpis[i - 1]]; }
      if (kdel) {
        const kd = n.kpis[+kdel.dataset.fbkdel];
        if (String(kd.k).startsWith("m_") && n.id && !(await askDialog({ icon: ["trash", "pink"], title: `Retirer le KPI « ${kd.l} » ?`, text: "Ses chiffres saisis à la main seront supprimés à l'enregistrement du funnel.", ok: "Retirer le KPI", cancel: "Garder le KPI", danger: true }))) return;
        n.kpis.splice(+kdel.dataset.fbkdel, 1);
      }
      fbDirty(); fbDrawCanvas(); return fbDrawInsp();
    }
    const tab = t.closest("[data-fbtab]");
    if (tab) { fb.tab = tab.dataset.fbtab; return fbDrawInsp(); }
    const kind = t.closest("[data-fbkind]");
    if (kind && fb.sel) { fbNode(fb.sel).kind = kind.dataset.fbkind; fbDirty(); fbDrawCanvas(); return fbDrawInsp(); }
    const lab = t.closest("[data-fe]");
    if (lab) { fbSelect(lab.dataset.fe, true); const b = $("#fbB"); if (b) { b.focus(); b.select(); } return; }
    const node = t.closest("#fbCanvas .fn");
    if (node) { if (fb.link) return fbTryLink(fb.link, node.dataset.k); return fbSelect(node.dataset.k, true); }
    if (t.closest("#fbCanvas") && !fb.link && fb.sel) fbSelect(null);
  });
  root.addEventListener("keydown", e => {
    const fb = S.fb;
    if (!fb) return;
    const node = e.target.closest && e.target.closest("#fbCanvas .fn");
    if (e.key === "Escape") { if (fb.link) { fb.link = null; fbDrawCanvas(); } else if (fb.sel) fbSelect(null); return; }
    if (!node || e.target !== node) return;
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); if (fb.link) fbTryLink(fb.link, node.dataset.k); else fbSelect(node.dataset.k, true); }
    if (e.key === "Delete" || e.key === "Backspace") { e.preventDefault(); fbDelete(node.dataset.k); }
  });
  root.addEventListener("input", e => {
    const fb = S.fb, t = e.target;
    if (!fb) return;
    if (t.id === "fbName") { fb.funnel.name = t.value; return fbDirty(); }
    if (t.closest("#fbForm")) { Object.assign(fb.funnel, readForm($("#fbForm"), "funnels"), { name: fb.funnel.name }); return fbDirty(); }
    const n = fb.sel && fbNode(fb.sel);
    if (n && t.dataset.fbkt !== undefined) { n.kpis[+t.dataset.fbkt].t = Math.max(0, +t.value || 0); return fbDirty(); }
    if (!n || !t.dataset.fbf || t.tagName === "SELECT") return;
    const k = t.dataset.fbf;
    n[k] = k === "target" ? Math.max(0, +t.value || 0) : t.value;
    if (k === "name") $("#fbHeadName").textContent = t.value || "Sans nom";
    fbDirty();
    if (k === "name" || k === "branch") fbPatch(n);
  });
  root.addEventListener("change", e => {
    const fb = S.fb, t = e.target;
    if (!fb) return;
    if (t.dataset.fbl) { const l = fb.links[t.dataset.fbl], v = +t.value; fb.links[t.dataset.fbl] = t.checked ? [...new Set([...l, v])] : l.filter(x => x !== v); return fbDirty(); }
    if (t.closest("#fbForm")) { Object.assign(fb.funnel, readForm($("#fbForm"), "funnels"), { name: fb.funnel.name }); return fbDirty(); }
    const n = fb.sel && fbNode(fb.sel);
    if (!n) return;
    if (t.matches("[data-fbch]")) {
      n.channels = $$("[data-fbch]:checked", root).map(c => c.value);
      const w = $(".fb-chwarn", root);
      if (w) w.hidden = !(needsCh(n.source) && !n.channels.length);
      if ($("#fbSupBox")) $("#fbSupBox").innerHTML = fbSupPicker(n); // les supports proposés suivent les canaux
      fbDirty();
      return fbDrawCanvas();
    }
    if (t.matches("[data-fbsup]")) {
      n.supports = $$("[data-fbsup]:checked", root).map(c => c.value);
      fbDirty();
      return fbDrawCanvas();
    }
    if (t.id === "fbKAdd") {
      if (t.value) { n.kpis.push({ k: t.value, t: 0 }); fbDirty(); fbDrawCanvas(); fbDrawInsp(); const s2 = $("#fbKAdd"); if (s2) s2.focus(); }
      return;
    }
    if (t.dataset.fbp) {
      n.parents = t.checked ? [...new Set([...n.parents, t.dataset.fbp])] : n.parents.filter(p => p !== t.dataset.fbp);
      fbFixKinds(); fbDirty(); fbDrawCanvas(); return fbDrawInsp();
    }
    if (t.tagName === "SELECT" && t.dataset.fbf) {
      n[t.dataset.fbf] = t.value;
      if (t.dataset.fbf === "source" && !LEAD_SRC(t.value)) n.segment = "";
      if (t.dataset.fbf === "source" && !n.kpis.length) n.kpis = kpiDefaults(t.value); // KPIs proposés d'office
      fbDirty(); fbDrawCanvas(); fbDrawInsp();
      if (t.dataset.fbf === "source" && needsCh(t.value) && !n.channels.length) { const d = $("#fbInsp .fb-ch details"); if (d) d.open = true; }
      return;
    }
  });
  // Glisser le « + » d'une étape jusqu'à une autre : les deux étapes sont reliées.
  root.addEventListener("pointerdown", e => {
    const port = e.target.closest(".fn-port");
    if (!port || e.button > 0 || !S.fb) return;
    const fb = S.fb, fl = port.closest(".fl"), stage = $(".fl-stage", fl), svg = $(".fl-svg", fl), from = port.dataset.fa;
    const z = fb.zoom || 1, p0 = [e.clientX, e.clientY];
    const at = (cx, cy) => { const r = stage.getBoundingClientRect(); return [(cx - r.left) / z, (cy - r.top) / z]; };
    const pr = port.getBoundingClientRect(), [sx, sy] = at(pr.left + pr.width / 2, pr.top + pr.height / 2);
    let line = null, ok = null, over = null;
    try { port.setPointerCapture(e.pointerId); } catch (err) {}
    const move = ev => {
      if (!line) {
        if (Math.hypot(ev.clientX - p0[0], ev.clientY - p0[1]) < 7) return;
        fbCloseMenu();
        ok = fbLinkTargets(from);
        fl.classList.add("linking");
        $$(".fn", stage).forEach(x => { x.classList.toggle("target", ok.has(x.dataset.k)); x.classList.toggle("origin", x.dataset.k === from); });
        line = document.createElementNS("http://www.w3.org/2000/svg", "path");
        line.setAttribute("class", "fl-e drag");
        svg.append(line);
      }
      const [x, y] = at(ev.clientX, ev.clientY);
      line.setAttribute("d", `M${sx},${sy} C${sx + 40},${sy} ${x - 40},${y} ${x},${y}`);
      const hit = document.elementFromPoint(ev.clientX, ev.clientY);
      const n = hit && hit.closest(".fn");
      if (over && over !== n) over.classList.remove("over");
      over = n && ok.has(n.dataset.k) ? n : null;
      if (over) over.classList.add("over");
    };
    const up = ev => {
      port.removeEventListener("pointermove", move);
      port.removeEventListener("pointerup", up);
      port.removeEventListener("pointercancel", up);
      if (!line) return;
      fb.dragEnd = Date.now();
      const hit = ev.type === "pointerup" ? document.elementFromPoint(ev.clientX, ev.clientY) : null;
      const n = hit && hit.closest(".fn");
      if (n && n.dataset.k !== from) fbTryLink(from, n.dataset.k);
      else fbDrawCanvas();
    };
    port.addEventListener("pointermove", move);
    port.addEventListener("pointerup", up);
    port.addEventListener("pointercancel", up);
  });
}

VIEWS["funnels/edit"] = { fn: async param => {
  const p = param || "new";
  if (!S.fb || S.fb.param !== p) {
    S.fb = null;
    if (p !== "new") {
      const f = REF("funnels").find(x => String(x.id) === p);
      if (!f) return empty("Ce funnel n'existe plus.", `<a class="btn" href="#funnels/list">Voir les funnels</a>`, { icon: ["alert-c", "pink"] });
      S.fb = fbState(f);
    }
  }
  return S.fb ? fbPage() : fbTemplatesPage();
}, after: () => { if (S.fb) fbMount(); else fbTplMount(); } };

/* ================================================================ actions globales */
document.addEventListener("click", async e => {
  const t = e.target.closest("[data-add],[data-edit],[data-del],[data-close],[data-funnel-edit],[data-shift],[data-report],[data-archive-del],[data-import],[data-copy]");
  if (!t) return;
  if (t.hasAttribute("data-close")) return closeModal();
  if (t.dataset.import) { $("#addMenu").hidden = true; return openImport(t.dataset.import); }
  if (t.dataset.copy) {
    const txt = ($(t.dataset.copy) || {}).textContent || "";
    try { await navigator.clipboard.writeText(txt); toast("Adresse copiée"); } catch (err) { toast("Copie impossible : sélectionnez l'adresse et copiez-la à la main.", "err"); }
    return;
  }
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
  if (t.dataset.funnelEdit !== undefined) return openFunnelEditor(t.dataset.funnelEdit);
  if (t.dataset.del) {
    const [ent, id] = t.dataset.del.split(":");
    const what = { campaigns: ["Supprimer cette campagne ?", "Ses résultats saisis seront supprimés avec elle.", "Supprimer la campagne"], pages: ["Supprimer cette page ?", "Toutes ses statistiques saisies seront supprimées avec elle.", "Supprimer la page"], funnels: ["Supprimer ce funnel ?", "Ses étapes et ses chiffres saisis seront supprimés avec lui.", "Supprimer le funnel"], projects: ["Supprimer ce projet ?", "Son historique de financement sera supprimé avec lui.", "Supprimer le projet"] }[ent] || ["Supprimer cette ligne ?", "Elle disparaîtra aussi des rapports.", "Supprimer"];
    if (!(await askDialog({ icon: ["trash", "pink"], title: what[0], text: what[1] + " Cette action est définitive.", ok: what[2], cancel: "Annuler", danger: true }))) return;
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
    if (!(await askDialog({ icon: ["archive", "pink"], title: "Supprimer ce rapport archivé ?", text: "La copie archivée disparaît. Les données, elles, restent : vous pourrez toujours recalculer ce rapport.", ok: "Supprimer le rapport", cancel: "Garder le rapport", danger: true }))) return;
    try { await api("archive_delete", {}, { id: +t.dataset.archiveDel }); route(); } catch (err) { toast(err.message); }
    return;
  }
  if (t.dataset.report) {
    const [act, type, date] = t.dataset.report.split(":");
    if (act === "print") return window.print();
    if (act === "csv") return reportCsv(S.lastReport);
    if (act === "archive") {
      try { await api("archive_save", {}, { type, date }); toast("Rapport archivé : retrouvez-le dans Overview › Archives"); } catch (err) { toast(err.message, "err"); }
      return;
    }
    if (act === "send") {
      const rec = S.boot.settings.recipients;
      const ans = await askDialog({ icon: ["send", "violet"], title: "Envoyer le rapport par email", text: "Séparez les adresses par des virgules. Le rapport part tel qu'il est affiché.", input: { value: rec.join(", "), label: "Adresses email", placeholder: "prenom@yinvesti.com", rows: 2 }, ok: "Envoyer le rapport", cancel: "Annuler" });
      if (ans === null) return;
      const emails = ans.split(/[\s,;]+/).filter(Boolean);
      if (!emails.length) return toast("Aucune adresse saisie : ajoutez au moins un destinataire.", "err");
      t.disabled = true;
      try { const d = await api("send_report", {}, { type, date, emails }); toast((d.sent.length ? "Rapport envoyé à " + d.sent.join(", ") : "Aucun envoi réussi") + (d.errors.length ? " — échecs : " + d.errors.join(" ; ") : ""), d.errors.length ? "err" : ""); }
      catch (err) { toast(err.message, "err"); }
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
    toast("Enregistré");
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
const ADD = [["Au quotidien"], ["contacts", "Lead", "user-plus", "blue"], ["inquiries", "Question", "message", "teal"], ["posts", "Action de communication", "megaphone", "violet"], ["projects", "Demande de projet", "folder", "pink"],
  ["Résultats à saisir"], ["social_stats", "Relevé d'un réseau social", "share", "violet"], ["ad_results", "Résultats publicitaires", "target", "orange"], ["web_stats", "Statistiques d'une page", "globe", "blue"], ["emails", "Campagne email", "mail", "teal"], ["funnel_entries", "Chiffres d'un funnel", "funnel", "violet"],
  ["À créer"], ["campaigns", "Campagne publicitaire", "target", "orange"], ["pages", "Page ou landing page", "link", "blue"], ["funnels", "Funnel", "funnel", "violet"]];
$("#addMenu").innerHTML = ADD.map(a => a.length === 1 ? `<p class="grp-h">${esc(a[0])}</p>` : `<button type="button" data-add="${a[0]}">${tile(a[2], a[3], "sm")}${esc(a[1])}</button>`).join("")
  + `<hr><button type="button" data-import="contacts">${tile("upload", "green", "sm")}Importer des leads (CSV)</button>`;
$("#addBtn").innerHTML = ic("plus") + '<span class="lbl">Nouveau</span>';
$("#burger").innerHTML = ic("menu");
$("#bell").innerHTML = ic("bell");
$("#logoutBtn").innerHTML = ic("logout");
$("#sideLinks").innerHTML = `<a href="#settings">${ic("settings", "sm")} Réglages</a><a href="../admin/">${ic("home", "sm")} Administration du site</a><button type="button" id="themeBtn">${ic("moon", "sm")} Thème clair / sombre</button>`;
$(".search").insertAdjacentHTML("afterbegin", ic("search"));

/* ================================================================ recherche globale (Ctrl K) */
const SEARCH_META = { contacts: ["user-plus", "blue"], inquiries: ["message", "teal"], posts: ["megaphone", "violet"], projects: ["folder", "pink"], campaigns: ["target", "orange"], emails: ["mail", "green"], funnels: ["funnel", "violet"], taxonomy: ["layers", "amber"] };
const SR = { q: "", groups: [], tab: "all", at: -1, timer: 0, seq: 0 };
function searchItems() {
  const gs = SR.tab === "all" ? SR.groups : SR.groups.filter(g => g.key === SR.tab);
  return gs.flatMap(g => g.items.map(it => Object.assign({ kind: g.key }, it)));
}
function paintSearch() {
  const pop = $("#searchPop");
  if (SR.q.length < 2) { pop.innerHTML = `<p class="search-empty">${ic("search", "sm")} Tapez au moins 2 lettres : un nom, un téléphone, un email, un canal, une campagne…</p>`; return; }
  if (!SR.groups.length) { pop.innerHTML = `<p class="search-empty">Aucun résultat pour « ${esc(SR.q)} ». Vérifiez l'orthographe, ou cherchez un téléphone, un email ou un nom de canal.</p>`; return; }
  const total = SR.groups.reduce((a, g) => a + g.count, 0);
  const items = searchItems();
  pop.innerHTML = `<div class="search-tabs" role="tablist">${[["all", "Tout", total], ...SR.groups.map(g => [g.key, g.label, g.count])].map(([k, l, n]) => `<button type="button" role="tab" data-stab="${k}" class="${SR.tab === k ? "on" : ""}" aria-selected="${SR.tab === k}">${esc(l)}<em>${num(n)}</em></button>`).join("")}</div>`
    + `<div role="listbox" aria-label="Résultats">${items.map((it, i) => { const m = SEARCH_META[it.kind] || ["file", "violet"]; return `<button type="button" class="sr${i === SR.at ? " on" : ""}" role="option" aria-selected="${i === SR.at}" data-sr="${i}">${tile(m[0], m[1], "sm")}<span><b>${esc(it.title)}</b><span class="sub">${esc(it.sub || "")}</span></span><span class="ty">${esc(it.type)}</span></button>`; }).join("")}</div>`
    + (SR.groups.some(g => g.count > g.items.length) ? `<p class="lbl" style="margin-top:8px">Seuls les 6 premiers résultats de chaque section sont affichés : précisez votre recherche pour aller plus vite.</p>` : "");
}
function openSearchItem(it) {
  closeSearch();
  $("#q").value = "";
  if (it.kind === "campaigns") location.hash = "#ads/campaign/" + it.id;
  else if (it.kind === "funnels") location.hash = "#funnels/stages/" + it.id;
  else if (it.kind === "taxonomy") location.hash = "#comm/taxonomy";
  else {
    const fake = document.createElement("button");
    fake.dataset.edit = it.kind + ":" + it.id;
    fake.hidden = true;
    document.body.append(fake);
    fake.click();
    fake.remove();
  }
}
function closeSearch() { $("#searchPop").hidden = true; SR.at = -1; $("#q").setAttribute("aria-expanded", "false"); }
$("#q").setAttribute("aria-controls", "searchPop");
$("#q").setAttribute("aria-expanded", "false");
$("#q").addEventListener("input", e => {
  SR.q = e.target.value.trim();
  SR.at = -1;
  clearTimeout(SR.timer);
  $("#searchPop").hidden = false;
  $("#q").setAttribute("aria-expanded", "true");
  if (SR.q.length < 2) { SR.groups = []; paintSearch(); return; }
  SR.timer = setTimeout(async () => {
    const my = ++SR.seq;
    try {
      const d = await api("search", { q: SR.q });
      if (my !== SR.seq) return;
      SR.groups = d.groups;
      if (SR.tab !== "all" && !SR.groups.some(g => g.key === SR.tab)) SR.tab = "all";
      paintSearch();
    } catch (err) { if (my === SR.seq) $("#searchPop").innerHTML = `<p class="search-empty">La recherche n'a pas abouti. ${esc(err.message)}</p>`; }
  }, 220);
});
$("#q").addEventListener("focus", () => { if (SR.q.length) { $("#searchPop").hidden = false; paintSearch(); } });
$("#q").addEventListener("keydown", e => {
  const items = searchItems();
  if (e.key === "Escape") { closeSearch(); $("#q").blur(); return; }
  if (e.key === "ArrowDown" || e.key === "ArrowUp") {
    if (!items.length) return;
    e.preventDefault();
    SR.at = (SR.at + (e.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
    paintSearch();
    const on = $("#searchPop .sr.on");
    if (on) on.scrollIntoView({ block: "nearest" });
  }
  if (e.key === "Enter" && items.length) { e.preventDefault(); openSearchItem(items[Math.max(0, SR.at)]); }
});
$("#searchPop").addEventListener("mousedown", e => e.preventDefault()); // garder le focus dans le champ
$("#searchPop").addEventListener("click", e => {
  const t = e.target.closest("[data-stab]"), r = e.target.closest("[data-sr]");
  if (t) { SR.tab = t.dataset.stab; SR.at = -1; paintSearch(); }
  if (r) openSearchItem(searchItems()[+r.dataset.sr]);
});
$("#q").addEventListener("blur", () => setTimeout(closeSearch, 120));
document.addEventListener("keydown", e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); $("#q").focus(); $("#q").select(); }
  else if (e.key === "/" && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) { e.preventDefault(); $("#q").focus(); }
});
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
/** Nom et initiale dans la carte utilisateur ; nombre de choses à traiter sur la cloche. */
function paintUser() {
  const n = (S.boot.settings.owner_name || "").trim();
  $("#userName").textContent = n || "Équipe marketing";
  $("#avatar").textContent = (n || S.boot.settings.org || "M").charAt(0).toUpperCase();
  $("#orgName").textContent = S.boot.settings.org;
}
function paintBell(b) {
  if (b) S.attention = (+b.stale || 0) + (+b.unassigned || 0) + (+b.stale_inquiries || 0);
  const el = $("#bell");
  el.innerHTML = ic("bell") + (S.attention ? `<span class="badge">${S.attention > 99 ? "99+" : S.attention}</span>` : "");
  el.title = S.attention ? `${S.attention} point(s) à traiter` : "Rien à traiter";
}
async function start() {
  const d = await api("bootstrap");
  S.boot = d;
  S.today = d.today;
  paintUser();
  paintBell(d.attention);
  $("#loginView").hidden = true;
  $("#app").hidden = false;
  route();
  if (!S.autoTimer) { S.autoTimer = setInterval(autoSync, 60 * 1000); setTimeout(autoSync, 8000); }
}

/**
 * Synchronisation Meta automatique, tant que le tableau de bord est ouvert : quand la dernière date de plus
 * que l'intervalle réglé dans Connexions. Silencieuse, sauf s'il y a du nouveau (ou une erreur, signalée une fois).
 */
async function autoSync() {
  const m = S.boot && S.boot.meta;
  if (!m || !m.connected || !+m.auto_sync || S.autoBusy || m.token_invalid) return;
  // Synchronisation pas terminée (gros import) : le lot suivant part tout de suite, sans attendre l'intervalle.
  const pend = S.autoPending || (m.last_result && m.last_result.more && !m.last_result.full ? m.last_result.pending : null);
  if (!pend && Date.now() / 1000 - (+m.last_sync || 0) < +m.auto_sync * 60) return;
  S.autoBusy = true;
  try {
    const d = await api("meta_sync", {}, pend ? { only: pend, resume: true, clean: S.autoClean !== false } : {});
    S.autoClean = (pend ? S.autoClean !== false : true) && d.result.ok;
    S.autoPending = d.result.more ? d.result.pending : null;
    S.boot.meta = d.meta;
    const t = d.result.totals;
    if (!d.result.ok && !S.autoWarned) { S.autoWarned = true; toast("Synchronisation automatique Meta : une étape a échoué (détail dans Connexions).", "err"); }
    if (t.added > 0) {
      S.cache.clear();
      const busy = !$("#modal").hidden || !$("#dlg").hidden || (document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName));
      toast(`Meta : ${num(t.added)} nouvelle(s) donnée(s) importée(s)` + (busy ? " — rechargez la page pour les voir" : ""));
      if (!busy && !location.hash.startsWith("#connexions") && !location.hash.startsWith("#funnels/edit")) route();
    }
  } catch (err) {
    // Synchronisation déjà en cours (onglet ou tâche cron) ou réseau coupé : on réessaiera à la prochaine minute.
    if (err.status === 401) clearInterval(S.autoTimer);
  }
  S.autoBusy = false;
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
