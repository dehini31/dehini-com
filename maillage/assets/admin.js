/*
 * Maillage — administration du propriétaire du site (dehini.com/maillage/admin.html).
 * Même session que /admin : inscriptions à valider, entreprises, réglages, démonstration.
 */
(function () {
  'use strict';
  const { html, icon, fmt, api } = ML;
  const $ = (s, r) => (r || document).querySelector(s);
  const app = () => $('#app');
  const KINDS = { brand: 'Marque', distributor: 'Distributeur' };
  const SIZES = { 1: '1 point de vente', '2-5': '2 à 5', '6-20': '6 à 20', '21+': 'Plus de 20' };
  const STATUS = { pending: ['En attente', 'badge-warn'], active: ['En ligne', 'badge-good'], suspended: ['Suspendue', 'badge-bad'] };

  const top = (inner) => html`
    <header class="mtop adm-top"><a href="./">${ML.logo()}</a><span class="badge badge-dark">Administration</span><span class="grow"></span><a class="btn btn-ghost btn-sm" href="../admin/">${icon('arrow-left', 'ico-sm')}Admin du site</a></header>
    <main id="view" class="adm-view">${inner}</main>`;

  async function load() {
    let d;
    try {
      // Le jeton anti-CSRF est celui de la session de /admin.
      const s = await fetch(new URL('../admin/api.php?action=session', ML.base), { credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : {})).catch(() => ({}));
      ML.csrf = s.csrf || '';
      d = await api('adm_overview');
    } catch (e) {
      app().innerHTML = top(html`<div class="card"><div class="empty">${icon('lock')}<h3>${e.code === 'auth' ? 'Connexion requise' : 'Chargement impossible'}</h3><p>${e.code === 'auth' ? 'Cette page s\'ouvre avec le mot de passe de l\'administration du site.' : e.message}</p><a class="btn btn-primary" href="../admin/">Ouvrir /admin</a></div></div>`);
      return;
    }
    const c = d.counts;
    const tile = (label, value, sub, hot) => html`<div class="tile ${hot ? 'is-hot' : ''}"><span class="tile-label">${label}</span><b class="tile-value">${fmt.num(value)}</b><span class="tile-sub dim">${sub || ''}</span></div>`;
    const real = d.companies.filter((x) => !x.demo);
    const demo = d.companies.filter((x) => x.demo);
    const row = (x) => {
      const [label, cls] = STATUS[x.status] || [x.status, ''];
      return html`<tr>
        <td><b>${x.name}</b><small class="muted">${KINDS[x.kind] || x.kind}${x.demo ? ' · démonstration' : ''}${x.size ? ' · ' + (SIZES[x.size] || x.size) : ''}</small></td>
        <td>${x.contact.name}<small class="muted">${x.contact.email}${x.contact.phone ? ' · ' + x.contact.phone : ''}</small></td>
        <td>${[x.city, x.zone !== x.city ? x.zone : ''].filter(Boolean).join(' · ') || html`<span class="dim">—</span>`}<small class="muted">Inscrite le ${fmt.date(x.created)}</small></td>
        <td class="num">${fmt.num(x.stores)} pdv · ${fmt.num(x.products)} produits<small class="muted">${fmt.plural(x.orders, 'commande')}</small></td>
        <td><span class="badge ${cls}">${label}</span></td>
        <td><div class="row row-wrap adm-actions">
          ${x.status !== 'active' ? html`<button class="btn btn-primary btn-sm" data-act="co" data-id="${x.id}" data-do="activate">${icon('check', 'ico-sm')}${x.status === 'pending' ? 'Valider' : 'Réactiver'}</button>` : html`<button class="btn btn-line btn-sm" data-act="co" data-id="${x.id}" data-do="suspend">Suspendre</button>`}
          <button class="btn btn-line btn-sm" data-act="open" data-id="${x.id}">${icon('external-link', 'ico-sm')}Ouvrir l'espace</button>
          <button class="btn btn-ghost btn-icon btn-sm" data-act="co" data-id="${x.id}" data-do="delete" data-name="${x.name}" aria-label="Supprimer ${x.name}">${icon('trash-2', 'ico-sm')}</button>
        </div></td>
      </tr>`;
    };
    const table = (list) => html`<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Entreprise</th><th>Contact</th><th>Lieu</th><th>Réseau</th><th>Statut</th><th></th></tr></thead><tbody>${list.map(row)}</tbody></table></div>`;
    app().innerHTML = top(html`
      <div class="vhead"><div><h1>Maillage</h1><p class="muted">Inscriptions, entreprises et réglages de la plateforme.</p></div><div class="vhead-actions"><a class="btn btn-line" href="./" target="_blank" rel="noopener">${icon('external-link')}Landing</a><a class="btn btn-line" href="explorer/" target="_blank" rel="noopener">${icon('map')}Plateforme</a></div></div>
      <div class="tiles">
        ${tile('Inscriptions à valider', c.pending, c.pending ? 'En attente de votre accord' : 'Rien en attente', c.pending > 0)}
        ${tile('Entreprises inscrites', c.companies, '+' + c.new7 + ' sur 7 jours')}
        ${tile('Points de vente', c.stores, 'Hors démonstration')}
        ${tile('Produits', c.products, 'Hors démonstration')}
        ${tile('Commandes', c.orders, 'Hors démonstration')}
        ${tile('Recherches · 7 jours', c.searches7, 'Toute la plateforme')}
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h2 class="card-title">Entreprises inscrites</h2><p class="card-sub">Une inscription validée apparaît sur la carte et dans le catalogue public.</p></div></div>
        ${real.length ? table(real) : html`<div class="empty">${icon('store')}<h3>Aucune inscription pour le moment</h3><p>Les entreprises qui s'inscrivent depuis la landing apparaissent ici.</p></div>`}
      </section>
      <div class="cols">
        <form class="card card-pad stack" data-form="settings">
          <h2 class="card-title">Réglages</h2>
          <label class="switch"><input type="checkbox" name="moderation" ${d.settings.moderation ? ML.raw('checked') : ''}><i></i><span>Valider chaque inscription avant sa mise en ligne<br><small class="muted">Désactivé : une entreprise inscrite est publiée tout de suite.</small></span></label>
          <div class="field"><label for="a-mail">Email prévenu à chaque inscription</label><input class="input" id="a-mail" name="notify_email" type="email" placeholder="vous@exemple.com" value="${d.settings.notify_email}"><span class="hint muted">${d.mail ? 'Envoyé par le compte email du site.' : 'Aucun compte SMTP n\'est configuré : l\'envoi passe par la fonction mail() du serveur (voir README).'}</span></div>
          <p class="form-error" data-error hidden></p>
          <div><button class="btn btn-primary" type="submit">Enregistrer</button></div>
        </form>
        <section class="card card-pad stack">
          <div><h2 class="card-title">Réseau de démonstration</h2><p class="card-sub">Trois marques et deux distributeurs fictifs, pour montrer la plateforme avant l'arrivée des premières entreprises.</p></div>
          ${d.demo ? html`<p>${fmt.plural(demo.length, 'entreprise')} de démonstration en ligne. La démonstration du tableau de bord (lecture seule) est proposée sur la landing.</p><div><button class="btn btn-danger" type="button" data-act="demo" data-do="delete">${icon('trash-2')}Supprimer la démonstration</button></div>`
            : html`<p class="muted">La démonstration n'est pas chargée.</p><div><button class="btn btn-line" type="button" data-act="demo" data-do="seed">${icon('refresh-cw')}Charger la démonstration</button></div>`}
        </section>
      </div>
      ${demo.length ? html`<section class="card card-pad"><div class="card-head"><h2 class="card-title">Entreprises de démonstration</h2></div>${table(demo)}</section>` : ''}`);
  }

  const post = async (action, body, el) => {
    ML.busy(el, true);
    try { await api(action, { body }); return true; } catch (e) { ML.fail(e); return false; } finally { ML.busy(el, false); }
  };
  ML.actions.co = async (el) => {
    const act = el.dataset.do;
    if (act === 'delete' && !await ML.confirm('Supprimer « ' + el.dataset.name + ' » avec ses points de vente, ses produits et ses commandes ? Cette action est définitive.', { ok: 'Supprimer', danger: true })) return;
    if (act === 'suspend' && !await ML.confirm('Suspendre cette entreprise ? Son réseau disparaît de la plateforme publique.', { ok: 'Suspendre', danger: true })) return;
    if (await post('adm_company', { id: +el.dataset.id, action: act }, el)) { ML.toast(act === 'activate' ? 'Entreprise en ligne.' : act === 'suspend' ? 'Entreprise suspendue.' : 'Entreprise supprimée.', 'good'); load(); }
  };
  ML.actions.open = async (el) => { if (await post('adm_open', { id: +el.dataset.id }, el)) window.open('espace/', '_blank', 'noopener'); };
  ML.actions.demo = async (el) => {
    const del = el.dataset.do === 'delete';
    if (del && !await ML.confirm('Supprimer toutes les données de démonstration (entreprises, points de vente, produits, commandes) ?', { ok: 'Supprimer', danger: true })) return;
    if (await post('adm_demo', { action: el.dataset.do }, el)) { ML.toast(del ? 'Démonstration supprimée.' : 'Démonstration chargée.', 'good'); load(); }
  };
  ML.forms.settings = async (f) => {
    try { await api('adm_settings', { body: ML.formData(f) }); ML.toast('Réglages enregistrés.', 'good'); } catch (e) { ML.fail(e, f); }
  };

  ML.bootstrap().then(load).catch((e) => { app().innerHTML = html`<div class="empty" style="padding-top:120px">${icon('wifi-off')}<h3>Chargement impossible</h3><p>${e.message}</p></div>`; });
})();
