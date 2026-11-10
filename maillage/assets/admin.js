/*
 * Maillage — administration du propriétaire du site (dehini.com/maillage/admin.html).
 * Même session que /admin : inscriptions à valider, entreprises, réglages, démonstration.
 */
(function () {
  'use strict';
  const { html, icon, fmt, api } = ML;
  const $ = (s, r) => (r || document).querySelector(s);
  const app = () => $('#app');
  const KINDS = () => ({ brand: ML.t('Marque'), distributor: ML.t('Distributeur') });
  const SIZES = () => ({ 1: ML.t('1 point de vente'), '2-5': ML.t('2 à 5'), '6-20': ML.t('6 à 20'), '21+': ML.t('Plus de 20') });
  const STATUS = () => ({ pending: [ML.t('En attente'), 'badge-warn'], active: [ML.t('En ligne'), 'badge-good'], suspended: [ML.t('Suspendue'), 'badge-bad'] });

  const top = (inner) => html`
    <header class="mtop adm-top"><a href="./">${ML.logo()}</a><span class="badge badge-dark">${ML.t('Administration')}</span><span class="grow"></span>${ML.langToggle()}<a class="btn btn-ghost btn-sm" href="../admin/">${icon('arrow-left', 'ico-sm')}${ML.t('Admin du site')}</a></header>
    <main id="view" class="adm-view">${inner}</main>`;

  async function load() {
    let d;
    try {
      // Le jeton anti-CSRF est celui de la session de /admin.
      const s = await fetch(new URL('../admin/api.php?action=session', ML.base), { credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : {})).catch(() => ({}));
      ML.csrf = s.csrf || '';
      d = await api('adm_overview');
    } catch (e) {
      app().innerHTML = top(html`<div class="card"><div class="empty">${icon('lock')}<h3>${e.code === 'auth' ? ML.t('Connexion requise') : ML.t('Chargement impossible')}</h3><p>${e.code === 'auth' ? ML.t("Cette page s'ouvre avec le mot de passe de l'administration du site.") : e.message}</p><a class="btn btn-primary" href="../admin/">${ML.t('Ouvrir /admin')}</a></div></div>`);
      return;
    }
    const c = d.counts;
    const tile = (label, value, sub, hot) => html`<div class="tile ${hot ? 'is-hot' : ''}"><span class="tile-label">${label}</span><b class="tile-value">${fmt.num(value)}</b><span class="tile-sub dim">${sub || ''}</span></div>`;
    const real = d.companies.filter((x) => !x.demo);
    const demo = d.companies.filter((x) => x.demo);
    const row = (x) => {
      const [label, cls] = STATUS()[x.status] || [x.status, ''];
      return html`<tr>
        <td><b>${x.name}</b><small class="muted">${KINDS()[x.kind] || x.kind}${x.demo ? ' · ' + ML.t('démonstration') : ''}${x.size ? ' · ' + (SIZES()[x.size] || x.size) : ''}</small></td>
        <td>${x.contact.name}<small class="muted">${x.contact.email}${x.contact.phone ? ' · ' + x.contact.phone : ''}</small></td>
        <td>${[x.city, x.zone !== x.city ? x.zone : ''].filter(Boolean).join(' · ') || html`<span class="dim">—</span>`}<small class="muted">${ML.t('Inscrite le')} ${fmt.date(x.created)}</small></td>
        <td class="num">${fmt.num(x.stores)} ${ML.t('pdv')} · ${fmt.num(x.products)} ${ML.t('produits')}<small class="muted">${fmt.plural(x.orders, 'commande')}</small></td>
        <td><span class="badge ${cls}">${label}</span></td>
        <td><div class="row row-wrap adm-actions">
          ${x.status !== 'active' ? html`<button class="btn btn-primary btn-sm" data-act="co" data-id="${x.id}" data-do="activate">${icon('check', 'ico-sm')}${x.status === 'pending' ? ML.t('Valider') : ML.t('Réactiver')}</button>` : html`<button class="btn btn-line btn-sm" data-act="co" data-id="${x.id}" data-do="suspend">${ML.t('Suspendre')}</button>`}
          <button class="btn btn-line btn-sm" data-act="open" data-id="${x.id}">${icon('external-link', 'ico-sm')}${ML.t('Ouvrir l\'espace')}</button>
          <button class="btn btn-ghost btn-icon btn-sm" data-act="co" data-id="${x.id}" data-do="delete" data-name="${x.name}" aria-label="${ML.t('Supprimer')} ${x.name}">${icon('trash-2', 'ico-sm')}</button>
        </div></td>
      </tr>`;
    };
    const table = (list) => html`<div class="tbl-wrap"><table class="tbl"><thead><tr><th>${ML.t('Entreprise')}</th><th>${ML.t('Contact')}</th><th>${ML.t('Lieu')}</th><th>${ML.t('Réseau')}</th><th>${ML.t('Statut')}</th><th></th></tr></thead><tbody>${list.map(row)}</tbody></table></div>`;
    app().innerHTML = top(html`
      <div class="vhead"><div><h1>Maillage</h1><p class="muted">${ML.t('Inscriptions, entreprises et réglages de la plateforme.')}</p></div><div class="vhead-actions"><a class="btn btn-line" href="./" target="_blank" rel="noopener">${icon('external-link')}${ML.t('Landing')}</a><a class="btn btn-line" href="explorer/" target="_blank" rel="noopener">${icon('map')}${ML.t('Plateforme')}</a></div></div>
      <div class="tiles">
        ${tile(ML.t('Inscriptions à valider'), c.pending, c.pending ? ML.t('En attente de votre accord') : ML.t('Rien en attente'), c.pending > 0)}
        ${tile(ML.t('Entreprises inscrites'), c.companies, '+' + c.new7 + ' ' + ML.t('sur 7 jours'))}
        ${tile(ML.t('Points de vente'), c.stores, ML.t('Hors démonstration'))}
        ${tile(ML.t('Produits'), c.products, ML.t('Hors démonstration'))}
        ${tile(ML.t('Commandes'), c.orders, ML.t('Hors démonstration'))}
        ${tile(ML.t('Recherches · 7 jours'), c.searches7, ML.t('Toute la plateforme'))}
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h2 class="card-title">${ML.t('Entreprises inscrites')}</h2><p class="card-sub">${ML.t('Une inscription validée apparaît sur la carte et dans le catalogue public.')}</p></div></div>
        ${real.length ? table(real) : html`<div class="empty">${icon('store')}<h3>${ML.t('Aucune inscription pour le moment')}</h3><p>${ML.t("Les entreprises qui s'inscrivent depuis la landing apparaissent ici.")}</p></div>`}
      </section>
      <div class="cols">
        <form class="card card-pad stack" data-form="settings">
          <h2 class="card-title">${ML.t('Réglages')}</h2>
          <label class="switch"><input type="checkbox" name="moderation" ${d.settings.moderation ? ML.raw('checked') : ''}><i></i><span>${ML.t('Valider chaque inscription avant sa mise en ligne')}<br><small class="muted">${ML.t('Désactivé : une entreprise inscrite est publiée tout de suite.')}</small></span></label>
          <div class="field"><label for="a-mail">${ML.t('Email prévenu à chaque inscription')}</label><input class="input" id="a-mail" name="notify_email" type="email" placeholder="vous@exemple.com" value="${d.settings.notify_email}"><span class="hint muted">${d.mail ? ML.t('Envoyé par le compte email du site.') : ML.t("Aucun compte SMTP n'est configuré : l'envoi passe par la fonction mail() du serveur (voir README).")}</span></div>
          <p class="form-error" data-error hidden></p>
          <div><button class="btn btn-primary" type="submit">${ML.t('Enregistrer')}</button></div>
        </form>
        <section class="card card-pad stack">
          <div><h2 class="card-title">${ML.t('Réseau de démonstration')}</h2><p class="card-sub">${ML.t("Trois marques et deux distributeurs fictifs, pour montrer la plateforme avant l'arrivée des premières entreprises.")}</p></div>
          ${d.demo ? html`<p>${fmt.plural(demo.length, 'entreprise')} ${ML.t('de démonstration en ligne. La démonstration du tableau de bord (lecture seule) est proposée sur la landing.')}</p><div><button class="btn btn-danger" type="button" data-act="demo" data-do="delete">${icon('trash-2')}${ML.t('Supprimer la démonstration')}</button></div>`
            : html`<p class="muted">${ML.t("La démonstration n'est pas chargée.")}</p><div><button class="btn btn-line" type="button" data-act="demo" data-do="seed">${icon('refresh-cw')}${ML.t('Charger la démonstration')}</button></div>`}
        </section>
      </div>
      ${demo.length ? html`<section class="card card-pad"><div class="card-head"><h2 class="card-title">${ML.t('Entreprises de démonstration')}</h2></div>${table(demo)}</section>` : ''}`);
  }

  const post = async (action, body, el) => {
    ML.busy(el, true);
    try { await api(action, { body }); return true; } catch (e) { ML.fail(e); return false; } finally { ML.busy(el, false); }
  };
  ML.actions.co = async (el) => {
    const act = el.dataset.do;
    if (act === 'delete' && !await ML.confirm(ML.t('Supprimer «') + ' ' + el.dataset.name + ML.t('» avec ses points de vente, ses produits et ses commandes ? Cette action est définitive.'), { ok: ML.t('Supprimer'), danger: true })) return;
    if (act === 'suspend' && !await ML.confirm(ML.t('Suspendre cette entreprise ? Son réseau disparaît de la plateforme publique.'), { ok: ML.t('Suspendre'), danger: true })) return;
    if (await post('adm_company', { id: +el.dataset.id, action: act }, el)) { ML.toast(act === 'activate' ? ML.t('Entreprise en ligne.') : act === 'suspend' ? ML.t('Entreprise suspendue.') : ML.t('Entreprise supprimée.'), 'good'); load(); }
  };
  ML.actions.open = async (el) => { if (await post('adm_open', { id: +el.dataset.id }, el)) window.open('espace/', '_blank', 'noopener'); };
  ML.actions.demo = async (el) => {
    const del = el.dataset.do === 'delete';
    if (del && !await ML.confirm(ML.t('Supprimer toutes les données de démonstration (entreprises, points de vente, produits, commandes) ?'), { ok: ML.t('Supprimer'), danger: true })) return;
    if (await post('adm_demo', { action: el.dataset.do }, el)) { ML.toast(del ? ML.t('Démonstration supprimée.') : ML.t('Démonstration chargée.'), 'good'); load(); }
  };
  ML.forms.settings = async (f) => {
    try { await api('adm_settings', { body: ML.formData(f) }); ML.toast(ML.t('Réglages enregistrés.'), 'good'); } catch (e) { ML.fail(e, f); }
  };

  ML.bootstrap().then(load).catch((e) => { app().innerHTML = html`<div class="empty" style="padding-top:120px">${icon('wifi-off')}<h3>${ML.t('Chargement impossible')}</h3><p>${e.message}</p></div>`; });
})();
