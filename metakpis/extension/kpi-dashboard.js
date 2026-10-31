// Stats Meta KPIs · Meta Ads KPI dashboard  v2.1.0  (FR / EN)
// Overlay for Meta Ads Manager, linked to a dehini.com/metakpis account.
// - Dashboard: reads only the table already loaded on screen. It scrolls the grid view, switches the
//   Campaigns / Ad sets / Ads tabs and (when you ask) reloads the page with another date range or column set.
//   It never clicks edit / publish / budget / toggle controls.
// - Excel export: dashboard data + (with the Meta Ads connection) placement, age, gender and ad copy.
// - Suggested campaign: built from your best KPIs, editable, created PAUSED through the official Meta API,
//   published only when you click "Publish".
// Network calls go through the extension (bridge.js → background.js → dehini.com), never from this page.
(() => {
  'use strict';
  if (window.__smk) { try { window.__smk.open(); } catch (e) { } return; }

  // ------------------------------------------------------------------ config
  const VERSION = '2.1.0';
  const LS_KEY = 'smk.settings.v2';
  const LS_OLD = 'rotiniaKpi.settings.v1';
  const SS_REOPEN = 'smk.reopen';
  const SITE_DEFAULT = 'https://dehini.com/metakpis/';
  const COL = { green: '#16A34A', orange: '#F59E0B', red: '#DC2626', gray: '#9CA3AF', neutral: '#475467' };
  const DEFAULTS = {
    targetCpa: 3,              // account currency — set your own in Settings
    cpaTolerancePct: 30,       // orange zone above target CPA
    minSpend: 3,               // below this spend → "Insufficient data"
    minImpressions: 500,       // below this → traffic KPIs shown gray
    killMultiplier: 2,         // spend ≥ k × target CPA with 0 purchases → Losing
    minPurchasesConfidence: 3, // winners with fewer purchases → "worth a closer look"
    holdFormula: 'thru_over_3s',
    colorNative: true,
    hideZeroSpend: true,
    lang: 'auto',
    t: {
      roas: { dir: 'up', good: 3, warn: 2 },
      ctr: { dir: 'up', good: 2, warn: 1 },
      cpc: { dir: 'down', good: 0.30, warn: 0.50 },
      cpm: { dir: 'down', good: 5, warn: 8 },
      freq: { dir: 'down', good: 2, warn: 3, strict: true },
      hook: { dir: 'up', good: 30, warn: 20 },
      hold: { dir: 'up', good: 20, warn: 10 },
      lpvr: { dir: 'up', good: 70, warn: 50 },
      cvr: { dir: 'up', good: 3, warn: 1.5 },   // purchases ÷ landing page views (or link clicks)
    },
  };
  const TMETA = { roas: { unit: '×' }, ctr: { unit: '%' }, cpc: { unit: 'cur' }, cpm: { unit: 'cur' }, freq: { unit: '' }, hook: { unit: '%' }, hold: { unit: '%' }, lpvr: { unit: '%' }, cvr: { unit: '%' } };
  const PURCHASE_ACTIONS = ['offsite_conversion.fb_pixel_purchase', 'omni_purchase', 'purchase', 'onsite_web_purchase'];
  const PURCHASE_RE = /achat|purchase|compra|acquist|kauf|شراء|aankoop|zakup|satın/i;
  const LEVELS = ['campaign', 'adset', 'ad'];
  const OBJ2LV = { CAMPAIGN_GROUP: 'campaign', CAMPAIGN: 'adset', ADGROUP: 'ad' };
  const VERDICT = { win: { c: 'green', order: 0 }, opt: { c: 'orange', order: 1 }, lose: { c: 'red', order: 2 }, ins: { c: 'gray', order: 3 } };
  const KPI_COLUMNS = [
    'spend', 'impressions', 'reach', 'frequency', 'cpm',
    'actions:link_click', 'cost_per_action_type:link_click', 'website_ctr:link_click',
    'actions:omni_landing_page_view',
    'actions:offsite_conversion.fb_pixel_purchase', 'cost_per_action_type:offsite_conversion.fb_pixel_purchase',
    'action_values:offsite_conversion.fb_pixel_purchase', 'website_purchase_roas:offsite_conversion.fb_pixel_purchase',
    'actions:video_view', 'video_thruplay_watched_actions:video_view', 'ctr', 'cpc',
  ];
  const DATE_PRESETS = ['today', 'yesterday', 'last_7d', 'last_14d', 'last_30d', 'this_month', 'last_month'];
  // Insights placement (publisher_platform + platform_position) → targeting placement names.
  const POS_MAP = {
    facebook: { feed: 'feed', facebook_stories: 'story', facebook_reels: 'facebook_reels', facebook_reels_overlay: 'facebook_reels_overlay', instream_video: 'instream_video', marketplace: 'marketplace', right_hand_column: 'right_hand_column', search: 'search', video_feeds: 'video_feeds', profile_feed: 'profile_feed', notification: 'notification', facebook_profile_feed: 'profile_feed' },
    instagram: { feed: 'stream', instagram_stories: 'story', instagram_reels: 'reels', instagram_explore: 'explore', instagram_explore_grid_home: 'explore_home', instagram_profile_feed: 'profile_feed', instagram_search: 'ig_search', instagram_profile_reels: 'profile_reels' },
    audience_network: { an_classic: 'classic', rewarded_video: 'rewarded_video' },
    messenger: { messenger_inbox: 'messenger_home', messenger_stories: 'story' },
  };
  const POSITIONS = {
    facebook: ['feed', 'story', 'facebook_reels', 'facebook_reels_overlay', 'instream_video', 'video_feeds', 'marketplace', 'right_hand_column', 'search', 'profile_feed', 'notification'],
    instagram: ['stream', 'story', 'reels', 'explore', 'explore_home', 'profile_feed', 'profile_reels', 'ig_search'],
    audience_network: ['classic', 'rewarded_video'],
    messenger: ['messenger_home', 'story'],
  };
  const PLATFORM_NAME = { facebook: 'Facebook', instagram: 'Instagram', audience_network: 'Audience Network', messenger: 'Messenger', threads: 'Threads', whatsapp: 'WhatsApp', unknown: '—' };
  const CTAS = ['SHOP_NOW', 'ORDER_NOW', 'BUY_NOW', 'LEARN_MORE', 'SIGN_UP', 'CONTACT_US', 'GET_OFFER', 'SEND_MESSAGE', 'WHATSAPP_MESSAGE', 'SUBSCRIBE', 'DOWNLOAD', 'APPLY_NOW', 'GET_QUOTE', 'BOOK_TRAVEL', 'NO_BUTTON'];

  // ------------------------------------------------------------------ texts (FR / EN)
  const STR = {
    fr: {
      sub_readonly: 'Lecture seule · données de cette vue Ads Manager', rescan: '↻ Relire', rescan_t: 'Relire le tableau',
      full: 'Tout analyser', full_t: 'Lire Campagnes, Ensembles et Publicités l\'un après l\'autre', export: '⬇ Excel', export_t: 'Exporter en Excel',
      propose: '✨ Campagne', propose_t: 'Proposer une campagne à partir de vos meilleurs KPI', settings: '⚙ Réglages', close_t: 'Fermer (Échap)',
      date_t: 'Période (recharge Ads Manager)', custom_range: 'Période personnalisée…', scanned: 'Analysé', not_scanned: 'Pas encore analysé',
      profile_t: 'Mon profil Stats Meta KPIs', launch_t: 'Stats Meta KPIs (Alt+K)',
      lv_campaign: 'Campagnes', lv_adset: 'Ensembles', lv_ad: 'Publicités', lv1_campaign: 'campagne', lv1_adset: 'ensemble', lv1_ad: 'publicité',
      dp_today: 'Aujourd\'hui', dp_yesterday: 'Hier', dp_last_7d: '7 derniers jours', dp_last_14d: '14 derniers jours', dp_last_30d: '30 derniers jours', dp_this_month: 'Ce mois-ci', dp_last_month: 'Le mois dernier',
      v_win: 'Gagnante', v_opt: 'À optimiser', v_lose: 'Perdante', v_ins: 'Données insuffisantes',
      vs_win: 'CPA et ROAS dans l\'objectif', vs_opt: 'À surveiller / optimiser', vs_lose: 'Perd de l\'argent', vs_ins: 'Pas assez de dépense / données',
      st_green: 'Bon', st_orange: 'À surveiller', st_red: 'À analyser', st_gray: 'Pas de données',
      m_roas: 'ROAS', m_ctr: 'CTR lien', m_cpc: 'CPC lien', m_cpm: 'CPM', m_freq: 'Fréquence', m_hook: 'Hook rate', m_hold: 'Hold rate', m_lpvr: 'Taux de vue de page',
      why_no_spend_col: 'Colonne Dépense absente de cette vue', why_below_min: 'Dépense {s} sous le minimum de {m}', why_lose_zero: '{s} dépensés, 0 achat',
      why_no_purchase_yet: 'Pas encore d\'achat après {s}', why_cpa_only: 'CPA {c} vs objectif {t} — ROAS absent de la vue', why_mixed: ' — CPA et ROAS en désaccord (vérifier le panier moyen)',
      psrc_col: 'Colonne Achats', psrc_results: 'Résultats (achats)', psrc_not_purch: 'Les résultats ne sont pas des achats', psrc_no_result: 'Pas de données de résultat', psrc_no_col: 'Aucune colonne d\'achats dans la vue', psrc_none: 'Pas de données d\'achat',
      d_ctr: 'CTR lien faible — visuel / offre', d_hook: 'Accroche faible dans les 3 premières secondes', d_cpm: 'CPM cher — audience / placement', d_cpc: 'Clics sur le lien chers',
      d_freq: 'Fréquence élevée — audience lassée', d_lpv: 'Clics perdus avant le chargement de la page', d_hold: 'Les spectateurs décrochent avant le ThruPlay',
      try_again: 'Réessayer', custom_title: 'Période personnalisée', from: 'Du', to: 'au', apply_reload: 'Appliquer (recharge Ads Manager)', cancel: 'Annuler',
      no_data: 'Pas encore de données pour ce niveau. Cliquez sur <b>Relire</b> pour lire le tableau ({lv}).', scan_now: 'Analyser maintenant',
      attribution: 'Attribution :', attr_default: 'celle d\'Ads Manager', rows_n: '{n} lignes', table_says: '(le tableau en indique {n})', scanned_at: 'Analysé à {t}',
      date_changed: 'La période d\'Ads Manager a changé depuis cette analyse.',
      target_default: 'Le CPA cible est réglé sur <b>{v}</b> (valeur par défaut). Indiquez le vôtre pour que les couleurs et verdicts correspondent à vos marges.', open_settings: 'Ouvrir les réglages',
      missing_cols: '<b>Absent de cette vue Ads Manager :</b> {l}. Ces KPI restent gris — rien n\'est estimé.', add_cols: 'Ajouter les colonnes KPI…',
      c_purchases: 'Achats', c_value: 'Valeur d\'achat', c_roas: 'ROAS', c_v3s: 'Lectures de 3 s', c_thru: 'ThruPlays', c_freq: 'Fréquence', c_cpm: 'CPM', c_lpv: 'Vues de page de destination',
      read_partial: '{r} lignes lues sur {e}. Relancez pour compléter.',
      card_spend: 'Montant dépensé', spend_waste: '{p} sur des lignes perdantes / sans achat', card_purch: 'Achats', purch_na: 'Données d\'achat absentes de la vue',
      purch_excl: 'hors {n} ligne(s) non optimisée(s) pour l\'achat', purch_col: 'Colonne Achats', purch_res: 'depuis Résultats (achats)',
      card_cpa: 'Coût par achat', target_le: 'objectif ≤ {v}', card_value: 'Valeur d\'achat', not_in_view: 'Absent de la vue', card_roas: 'ROAS', no_value: 'Aucune valeur suivie', green_ge: 'vert ≥ {v}',
      card_ctr: 'CTR lien', ctr_sub: 'clics sur le lien ÷ impressions', card_cpc: 'CPC lien', cpc_sub: 'coût par clic sur le lien', card_cpm: 'CPM', cpm_sub: 'coût pour 1 000 impressions',
      card_freq: 'Fréquence (ligne max.)', card_lpv: 'Vues de page ÷ clics', lpv_sub: 'taux de vue de page', card_hook: 'Hook rate', hook_sub: 'lectures 3 s ÷ impressions', no_video: 'Pas de lecture vidéo',
      card_hold: 'Hold rate', no_thru: 'Pas de ThruPlay', hold_3s: 'ThruPlays ÷ lectures 3 s', hold_impr: 'ThruPlays ÷ impressions', na: 'n/d',
      glance: 'En un coup d\'œil', detail: '{lv} — détail', filter_ph: 'Filtrer par nom…', all: 'Toutes', sort: 'Trier', hide_zero: 'Masquer les lignes sans dépense',
      foot_src_col: 'la colonne Achats', foot_src_res: 'la colonne Résultats lorsque son type de résultat est un achat',
      foot: 'Les verdicts combinent CPA et ROAS — jamais le CTR seul. Les achats viennent de {src}, avec l\'attribution choisie dans Ads Manager ; les colonnes de comparaison d\'attribution sont ignorées pour ne rien compter deux fois. « — » = indicateur non fourni ou non applicable ; gris = pas assez de données (dépense &lt; {ms} ou impressions &lt; {mi}) ou colonne absente de la vue. Le CTR/CPC lien est distinct du CTR/CPC (tous). En paiement à la livraison, les achats du pixel sont des commandes passées, pas confirmées ni livrées. Les totaux sont la somme des lignes ; couverture et fréquence ne s\'additionnent pas : la carte montre la fréquence la plus haute.',
      th_verdict: 'Verdict', th_name: 'Nom', th_spend: 'Dépense', th_purch: 'Achats', th_cpa: 'CPA', th_value: 'Valeur', th_roas: 'ROAS', th_ctrLink: 'CTR lien', th_cpcLink: 'CPC lien', th_cpm: 'CPM',
      th_frequency: 'Fréq.', th_lpvRate: 'LPV/clic', th_hook: 'Hook', th_hold: 'Hold', th_impressions: 'Impr.', th_reach: 'Couverture', th_linkClicks: 'Clics lien', th_lpv: 'LPV', th_v3s: 'Lectures 3 s', th_thru: 'ThruPlays', th_ctrAll: 'CTR (tous)', th_cpcAll: 'CPC (tous)',
      best_tag: '★ TOP', worst_tag: '▼ PIRE', no_purch: 'Aucun achat', insufficient: 'Données insuffisantes', no_rows: 'Aucune ligne ne correspond.',
      q1: 'Quelles campagnes performent ?', q2: 'Quels ensembles optimiser ?', q3: 'Quelles publicités perdent de l\'argent ?', q4: 'Quelles publicités analyser de plus près ?', q5: 'Où le budget est-il gaspillé ?',
      level_lbl: 'Niveau : {lv}', not_scanned_yet: '{lv} : pas encore analysé.', scan_lv: 'Analyser : {lv}',
      e1: 'Aucune campagne n\'atteint à la fois le CPA et le ROAS cibles sur cette période.', cpa_roas_na: 'CPA (ROAS n/d)', e2: 'Aucun ensemble en zone orange.', e3: 'Aucune publicité ne perd d\'argent avec ces seuils.',
      a4_few: 'Gagnante sur peu d\'achats — à confirmer avec plus de budget', a4_mixed: 'CPA et ROAS en désaccord', a4_clicks: 'Beaucoup de clics, peu de ventes — vérifier page / offre', a4_early: 'Signal précoce : bon CTR, peu de dépense', e4: 'Rien d\'ambigu pour l\'instant.',
      of_total: '{p} de {t}', e5: 'Aucune dépense sur des lignes perdantes ou sans achat.',
      set_title: 'Réglages et seuils des KPI', set_sub: 'Enregistrés dans ce navigateur uniquement. Valeurs dans la devise du compte ({c}).',
      s_target: 'CPA cible ({c})', s_target_h: 'Vert à ce coût par achat ou en dessous.', s_tol: 'Zone orange du CPA (% au-dessus de l\'objectif)', s_tol_h: 'Rouge au-delà de objectif × (1 + ce %).',
      s_minspend: 'Dépense minimum pour un verdict ({c})', s_minspend_h: 'En dessous → « Données insuffisantes ».', s_minimpr: 'Impressions minimum pour les KPI de trafic', s_minimpr_h: 'CTR, CPC, CPM, fréquence, hook.',
      s_kill: '« Perdante » après une dépense sans achat (× CPA cible)', s_conf: 'Achats nécessaires pour croire une gagnante', s_hold: 'Formule du hold rate', hold_opt1: 'ThruPlays ÷ lectures de 3 s', hold_opt2: 'ThruPlays ÷ impressions',
      s_color: 'Colorer les cellules du tableau Ads Manager', yes: 'Oui', no: 'Non', s_color_h: 'CTR, CPC, CPM, fréquence, CPA, ROAS + barre de verdict sur les noms.',
      s_lang: 'Langue de l\'extension', lang_auto: 'Automatique (langue d\'Ads Manager)',
      th_kpi: 'KPI', th_green: 'Vert', th_orange: 'Orange', th_red: 'Rouge', otherwise: 'sinon', reset_defaults: 'Valeurs par défaut', save: 'Enregistrer',
      reading: 'Lecture du tableau…', overlay_note: 'Lecture seule — le tableau défile brièvement derrière ce panneau.', opening_tab: 'Ouverture de l\'onglet {lv}…', reading_lv: 'Lecture : {lv}…', returning: 'Retour à votre onglet…',
      err_tab_missing: 'Onglet {lv} introuvable.', err_tab_hidden: 'L\'onglet {lv} n\'est pas visible.', err_tab_timeout: 'L\'onglet {lv} ne s\'est pas chargé à temps.',
      err_open_table: 'Ouvrez d\'abord le tableau Campagnes, Ensembles ou Publicités.', err_no_table: 'Tableau Ads Manager introuvable sur cette page.',
      kpicols_all: 'Toutes les colonnes KPI sont déjà dans cette vue.',
      kpicols_confirm: 'Recharger Ads Manager avec les colonnes KPI ajoutées à cette vue ?\n\nAjoute : achats, coût par achat, valeur d\'achat, ROAS, lectures de 3 s, ThruPlays (et les colonnes de trafic manquantes).\n\nRien n\'est modifié dans vos campagnes. Ads Manager peut garder cette disposition de colonnes ; vous pouvez revenir en arrière depuis son menu Colonnes.',
      bad_range: 'Choisissez une période valide.',
      gate_title: 'Connectez-vous pour utiliser Stats Meta KPIs', gate_p: 'Un compte gratuit sur dehini.com relie l\'extension à votre profil : vos analyses, exports Excel et campagnes y sont affichés.',
      gate_login: 'Se connecter', gate_signup: 'Créer un compte gratuit', gate_hint: 'Déjà connecté sur le site ? Ouvrez votre profil et cliquez sur « Connecter l\'extension ». Ce panneau se met à jour tout seul.',
      gate_note: 'En vous connectant, vous acceptez que l\'extension partage avec Stats Meta KPIs les statistiques qu\'elle lit (KPI, ventilations, textes d\'annonces) pour alimenter votre profil et améliorer l\'extension. Désactivable à tout moment dans votre profil.',
      gate_checking: 'Vérification de votre connexion…', gate_broken: 'L\'extension est incomplète (le module de connexion ne répond pas). Réinstallez-la depuis dehini.com/metakpis, puis rechargez Ads Manager.',
      offline: 'Serveur Stats Meta KPIs injoignable : le tableau de bord fonctionne, mais le partage, l\'export Meta et les campagnes sont indisponibles pour le moment.',
      share_off: 'Partage désactivé', my_profile: 'Mon profil',
      ex_title: 'Exporter en Excel', ex_table: 'Tableau de bord (données de la vue Ads Manager)', ex_summary: 'Résumé', ex_not_scanned: 'pas encore analysé — cliquez pour analyser',
      ex_meta: 'Statistiques détaillées (connexion Meta Ads)', ex_placement: 'Placements', ex_age: 'Âge', ex_gender: 'Genre', ex_copy: 'Textes des annonces (titre, texte, description)',
      ex_level: 'Ventiler par', lvl_account: 'Compte entier', lvl_campaign: 'Campagne', lvl_adset: 'Ensemble de publicités', lvl_ad: 'Publicité', ex_period: 'Période',
      ex_meta_off: 'Connectez Meta Ads dans votre profil pour exporter les placements, âges, genres et textes des annonces.', connect_meta: 'Connecter Meta Ads',
      ex_meta_unconf: 'La connexion Meta Ads n\'est pas encore activée sur dehini.com.', ex_no_act: 'Ouvrez un compte publicitaire dans Ads Manager (paramètre act= dans l\'adresse).',
      ex_go: 'Télécharger le fichier Excel', ex_fetch: 'Récupération des statistiques Meta…', ex_done: 'Fichier téléchargé ✓ ({n} feuilles)', ex_copy_warn: 'textes des annonces non lus ({m})', ex_nothing: 'Cochez au moins une donnée à exporter.',
      sh_summary: 'Résumé', sh_campaign: 'Campagnes', sh_adset: 'Ensembles', sh_ad: 'Publicités', sh_placement: 'Placements', sh_age: 'Âge', sh_gender: 'Genre', sh_copy: 'Textes des annonces',
      x_campaign: 'Campagne', x_adset: 'Ensemble de publicités', x_ad: 'Publicité', x_platform: 'Plateforme', x_position: 'Position', x_age: 'Âge', x_gender: 'Genre',
      x_spend: 'Dépense', x_impr: 'Impressions', x_reach: 'Couverture', x_freq: 'Fréquence', x_clicks: 'Clics (tous)', x_linkclicks: 'Clics sur le lien', x_ctrlink: 'CTR lien (%)', x_cpclink: 'CPC lien',
      x_cpm: 'CPM', x_purch: 'Achats', x_cpa: 'Coût par achat', x_value: 'Valeur d\'achat', x_roas: 'ROAS', x_lpv: 'Vues de page', x_v3s: 'Lectures 3 s', x_thru: 'ThruPlays', x_hook: 'Hook rate (%)', x_hold: 'Hold rate (%)',
      x_verdict: 'Verdict', x_reason: 'Explication', x_delivery: 'Diffusion', x_status: 'Statut', x_format: 'Format', x_title: 'Titre', x_body: 'Texte principal', x_desc: 'Description', x_link: 'Lien', x_cta: 'Bouton',
      x_ctrall: 'CTR tous (%)', x_cpcall: 'CPC (tous)', x_lpvrate: 'Vues de page ÷ clics (%)', x_objective: 'Objectif', x_ad_id: 'ID publicité',
      g_female: 'Femmes', g_male: 'Hommes', g_unknown: 'Inconnu', g_all: 'Tous',
      fm_link: 'Image / lien', fm_video: 'Vidéo', fm_carousel: 'Carrousel', fm_dynamic: 'Dynamique (plusieurs textes)', fm_post: 'Publication existante', fm_photo: 'Photo',
      sum_title: 'Stats Meta KPIs — export', sum_account: 'Compte publicitaire', sum_period: 'Période', sum_generated: 'Généré le', sum_by: 'Par', sum_attr: 'Attribution', sum_level: 'Niveau',
      sum_rows: 'Lignes', sum_target: 'CPA cible', sum_meta_note: 'Placements, âges, genres et textes : API Marketing de Meta (attribution définie dans chaque ensemble de publicités).', sum_totals: 'Totaux — {lv}',
      pr_title: 'Campagne proposée à partir de vos meilleurs KPI', pr_loading: 'Analyse de vos meilleurs KPI ({s} → {u})…', pr_loading_src: 'Lecture de l\'ensemble et de l\'annonce source…',
      pr_meta_off: 'Connectez Meta Ads dans votre profil : l\'extension pourra lire vos âges, genres, placements et textes, puis créer la campagne (en pause).',
      pr_no_ads: 'Aucune publicité n\'a dépensé sur cette période : choisissez une autre période.', pr_period: 'Période analysée', pr_rerun: 'Relancer l\'analyse',
      pr_sec_campaign: 'Campagne', pr_name: 'Nom de la campagne', pr_budget: 'Budget quotidien ({c})', pr_budget_h: 'Budget de campagne Advantage+, stratégie « coût le plus bas ». Suggestion : {v}.', pr_objective: 'Objectif',
      pr_sec_audience: 'Audience', pr_countries: 'Lieux, centres d\'intérêt, audiences', pr_countries_h: 'Repris de l\'ensemble source « {n} ».', pr_age: 'Âge', pr_to: 'à', pr_gender: 'Genre',
      pr_adv: 'Audience Advantage+ (Meta peut aller au-delà de l\'âge et du genre choisis, qui deviennent des suggestions)',
      pr_sec_place: 'Placements', pr_place_auto: 'Placements Advantage+ (automatiques, conseillé)', pr_place_manual: 'Placements manuels',
      pr_sec_ad: 'Publicité', pr_ad_name: 'Nom de la publicité', pr_body: 'Texte principal', pr_headline: 'Titre', pr_desc: 'Description', pr_link: 'Lien de destination', pr_cta: 'Bouton d\'appel à l\'action',
      pr_not_editable: 'Cette annonce ({f}) ne permet pas de changer les textes : la nouvelle publicité réutilisera la même création, textes compris.', pr_visual: 'Même visuel que la meilleure annonce.',
      pr_why: 'Pourquoi ces choix', pr_best_ad: 'Meilleure annonce', pr_basis_ctr: 'Aucun achat sur la période : choix fait sur le CTR lien.', pr_src_adset: 'Ensemble source',
      pr_kept: 'retenu', pr_dropped: 'écarté', pr_little: 'peu de données', pr_seg: 'Segment',
      pr_age_why: 'Âges retenus : {a} (coût par achat dans l\'objectif ou meilleur ROAS).', pr_age_all: 'Pas d\'écart net entre les âges : tous gardés.',
      pr_gender_all: 'Écart trop faible entre femmes et hommes : les deux sont gardés.', pr_gender_one: '{g} nettement plus rentables : ciblage resserré.',
      pr_place_auto_why: 'Aucun placement ne perd clairement d\'argent : placements automatiques conseillés.', pr_place_manual_why: 'Placements perdants écartés : {l}.',
      pr_create: 'Créer sur Meta (en pause)', pr_creating: 'Création sur Meta…', pr_safe: 'Rien n\'est dépensé : la campagne est créée en pause. Vous pourrez la vérifier dans Ads Manager, puis la publier.',
      pr_done_t: 'Campagne créée en pause ✓', pr_done_p: '« {n} » est dans votre compte publicitaire, en pause. Vérifiez-la, puis publiez-la.', pr_texts_kept: 'Textes repris de la création d\'origine (format non modifiable).',
      pr_open: 'Ouvrir dans Ads Manager', pr_publish: 'Publier maintenant', pr_publish_confirm: 'Publier « {n} » ? La diffusion commence après l\'examen de Meta et peut dépenser jusqu\'à {b} par jour.',
      pr_published: 'Campagne publiée ✓ La diffusion démarre après l\'examen de la publicité par Meta.', pr_pause: 'Remettre en pause', pr_paused: 'Campagne en pause.', pr_profile: 'Voir dans mon profil', pr_new: 'Nouvelle proposition',
      pr_err_budget: 'Indiquez un budget quotidien.', pr_err_age: 'L\'âge minimum doit être inférieur ou égal à l\'âge maximum.', pr_err_place: 'Choisissez au moins un placement.', pr_err_name: 'Donnez un nom à la campagne.', pr_err_link: 'Le lien doit commencer par https://',
      pr_years: 'ans', pr_ads_n: '{n} publicités analysées', pr_regions: '{n} région(s)', pr_cities: '{n} ville(s)', pr_auds: '{n} audience(s) personnalisée(s)',
      obj_OUTCOME_SALES: 'Ventes', obj_OUTCOME_TRAFFIC: 'Trafic', obj_OUTCOME_LEADS: 'Prospects', obj_OUTCOME_ENGAGEMENT: 'Interactions', obj_OUTCOME_AWARENESS: 'Notoriété', obj_OUTCOME_APP_PROMOTION: 'Promotion d\'app',
      cta_SHOP_NOW: 'Acheter', cta_ORDER_NOW: 'Commander', cta_BUY_NOW: 'Acheter maintenant', cta_LEARN_MORE: 'En savoir plus', cta_SIGN_UP: 'S\'inscrire', cta_CONTACT_US: 'Nous contacter', cta_GET_OFFER: 'Profiter de l\'offre',
      cta_SEND_MESSAGE: 'Envoyer un message', cta_WHATSAPP_MESSAGE: 'Message WhatsApp', cta_SUBSCRIBE: 'S\'abonner', cta_DOWNLOAD: 'Télécharger', cta_APPLY_NOW: 'Postuler', cta_GET_QUOTE: 'Demander un devis', cta_BOOK_TRAVEL: 'Réserver', cta_NO_BUTTON: 'Aucun bouton',
      pos_facebook_feed: 'Fil', pos_facebook_story: 'Stories', pos_facebook_facebook_reels: 'Reels', pos_facebook_facebook_reels_overlay: 'Reels (bannières)', pos_facebook_instream_video: 'Vidéos in-stream', pos_facebook_video_feeds: 'Fils vidéo',
      pos_facebook_marketplace: 'Marketplace', pos_facebook_right_hand_column: 'Colonne de droite', pos_facebook_search: 'Résultats de recherche', pos_facebook_profile_feed: 'Profil', pos_facebook_notification: 'Notifications',
      pos_instagram_stream: 'Fil', pos_instagram_story: 'Stories', pos_instagram_reels: 'Reels', pos_instagram_explore: 'Explorer', pos_instagram_explore_home: 'Accueil Explorer', pos_instagram_profile_feed: 'Profil', pos_instagram_profile_reels: 'Reels du profil', pos_instagram_ig_search: 'Recherche',
      pos_audience_network_classic: 'Native, bannière, interstitiel', pos_audience_network_rewarded_video: 'Vidéos avec récompense', pos_messenger_messenger_home: 'Boîte de réception', pos_messenger_story: 'Stories',
      err_meta_off: 'Meta Ads n\'est pas connecté : connectez-le dans votre profil.', err_meta_expired: 'L\'accès Meta a expiré : reconnectez Meta Ads dans votre profil.', err_meta_unconfigured: 'La connexion Meta Ads n\'est pas encore activée sur dehini.com.',
      err_meta_error: 'Meta a refusé : {m}', err_meta_rate: 'Meta limite les requêtes pour le moment : réessayez dans quelques minutes.', err_auth: 'Session de l\'extension expirée : reconnectez-vous.',
      err_network: 'Serveur Stats Meta KPIs injoignable.', err_rate: 'Trop de demandes : patientez un peu.', err_bad_account: 'Compte publicitaire invalide.', err_bad_date: 'Période invalide.', err_bad_budget: 'Budget invalide.',
      err_bad_placements: 'Choisissez au moins un placement.', err_bad_link: 'Lien invalide (https://…).', err_bad_source: 'Annonce source inutilisable.', err_generic: 'Une erreur est survenue.', err_bridge: 'L\'extension ne répond pas : rechargez la page.',
      th_action: "Que faire ?",
      th_cvr: "Conv.",
      m_cvr: "Taux de conversion (achats ÷ vues de page)",
      card_cvr: "Taux de conversion",
      cvr_sub: "achats ÷ vues de page",
      x_cvr: "Taux de conversion (%)",
      x_stage: "Étape à traiter",
      x_action: "Que faire en priorité",
      x_comments: "Tous les commentaires",
      cause: "cause probable : {c}",
      note_adset: "→ à régler dans l'ensemble de publicités.",
      note_ads: "→ voir le détail au niveau Publicités.",
      funnel: "Tunnel :",
      adv_none: "Rien à signaler.",
      show_comments: "Tous les commentaires",
      plan_t: "Plan d'action — {lv}",
      plan_all: "Tout afficher",
      plan_h: "Nombre de lignes par étape à traiter en priorité (pour les perdantes : la cause probable) et dépense concernée. Cliquez pour filtrer le tableau, puis sur « Que faire ? » pour le détail.",
      sg_data: "Données",
      sg_learning: "Apprentissage",
      sg_delivery: "Diffusion",
      sg_audience: "Ciblage",
      sg_hook: "Accroche",
      sg_hold: "Rétention",
      sg_click: "Clic",
      sg_offer: "Offre",
      sg_landing: "Page",
      sg_conversion: "Conversion",
      sg_value: "Rentabilité",
      sg_scale: "Budget",
      sg_cut: "Couper",
      adv_nodata_t: "Pas encore assez de données",
      adv_nodata_w: "{i} impressions, {s} dépensés (minimum : {mi} impressions ou {ms}).",
      adv_nodata_d: "Laisser tourner sans rien modifier|Juger seulement une fois le minimum atteint",
      adv_learnlim_t: "Apprentissage limité",
      adv_learnlim_w: "Meta n'obtient pas assez de conversions (≈ 50 par semaine) pour bien optimiser.",
      adv_learnlim_d: "Regrouper les ensembles qui visent la même audience|Augmenter le budget de l'ensemble|Ou optimiser pour un événement plus fréquent (ajout au panier, début de commande)",
      adv_learning_t: "En phase d'apprentissage",
      adv_learning_w: "La diffusion se stabilise encore.",
      adv_learning_d: "Ne pas toucher au budget, au ciblage ni aux créas pendant l'apprentissage (≈ 7 jours ou 50 conversions)",
      adv_cpm_t: "Diffusion trop chère (CPM)",
      adv_cpm_w: "CPM {v}, au-dessus de {w}.",
      adv_cpm_d: "Élargir l'audience (Audience Advantage+, moins d'exclusions et d'intérêts)|Passer en placements Advantage+ ou ajouter Reels et Stories|Vérifier le classement de qualité de la publicité|Éviter que plusieurs ensembles visent la même audience",
      adv_freq_hi_t: "Audience saturée",
      adv_freq_hi_w: "Fréquence {v} : les mêmes personnes voient la publicité trop souvent.",
      adv_freq_hi_d: "Ajouter 2 ou 3 nouvelles créas|Élargir l'audience ou ajouter un lookalike|Exclure les acheteurs récents",
      adv_freq_mid_t: "Fréquence à surveiller",
      adv_freq_mid_w: "Fréquence {v}.",
      adv_freq_mid_d: "Préparer de nouvelles créas avant la saturation",
      adv_target_t: "Ciblage peu réceptif",
      adv_target_w: "Le CPM est bas ({cpm}) mais le CTR lien est faible ({ctr}) : la publicité est vue par des gens peu intéressés.",
      adv_target_d: "Tester une audience plus qualifiée : lookalike d'acheteurs, intérêts proches du produit|Vérifier âge, genre et pays (export Excel par âge et genre)|Retirer les placements à faible intention (Audience Network)",
      adv_hook_lo_t: "Accroche faible (3 premières secondes)",
      adv_hook_lo_w: "Seulement {v} des personnes regardent 3 secondes (objectif ≥ {g} %).",
      adv_hook_lo_d: "Montrer le produit ou le résultat dès la première seconde|Ajouter un texte fort à l'écran : problème, promesse ou prix|Commencer par du mouvement ou un visage, pas par un logo|Tester 3 accroches différentes sur la même vidéo",
      adv_hook_mid_t: "Accroche à renforcer",
      adv_hook_mid_w: "{v} regardent 3 secondes (objectif ≥ {g} %).",
      adv_hook_mid_d: "Tester une ouverture plus directe (produit, prix ou résultat dès le début)",
      adv_hold_lo_t: "La vidéo perd l'attention",
      adv_hold_lo_w: "Hold rate {v} : les gens décrochent avant la fin.",
      adv_hold_lo_d: "Raccourcir à 15–20 secondes|Montrer le bénéfice et l'offre plus tôt|Rythme plus rapide, sous-titres, gros plans sur le produit",
      adv_hold_mid_t: "Rétention moyenne",
      adv_hold_mid_w: "Hold rate {v}.",
      adv_hold_mid_d: "Couper les passages lents et avancer l'offre",
      adv_ctr_lo_t: "Peu de clics : visuel ou message",
      adv_ctr_lo_w: "CTR lien {v} (objectif ≥ {g} %).",
      adv_ctr_lo_d: "Nouveau visuel : produit en situation, avant / après, avis clients|Tester un autre angle : problème, bénéfice, prix, urgence|Mettre le bénéfice principal dans la première ligne du texte",
      adv_ctr_mid_t: "CTR à améliorer",
      adv_ctr_mid_w: "CTR lien {v} (objectif ≥ {g} %).",
      adv_ctr_mid_d: "Tester une variante du visuel ou de la première ligne du texte",
      adv_watch_noclick_t: "Ils regardent mais ne cliquent pas : l'offre",
      adv_watch_noclick_w: "Bonne accroche ({h}) mais CTR lien faible ({v}) : l'offre ou l'appel à l'action ne convainc pas.",
      adv_watch_noclick_d: "Rendre l'offre claire et visible : prix, réduction, livraison, paiement à la livraison|Donner une raison d'agir maintenant : stock limité, promo jusqu'à une date|Dire clairement quoi faire (« Commandez maintenant ») avec le bon bouton",
      adv_cta_t: "Interactions sans clic sur le lien",
      adv_cta_w: "CTR (tous) {a} contre CTR lien {v} : les gens réagissent mais ne vont pas sur la page.",
      adv_cta_d: "Ajouter un appel à l'action explicite dans la vidéo et le texte|Vérifier le bouton (Acheter, Commander)|Éviter les visuels qui invitent à cliquer sur l'image plutôt que sur le lien",
      adv_lpv_lo_t: "Page de destination : clics perdus",
      adv_lpv_lo_w: "{lost} des clics n'arrivent pas sur la page (vues de page ÷ clics : {v}).",
      adv_lpv_lo_d: "Tester la vitesse sur mobile (objectif moins de 3 secondes) et alléger les images|Vérifier que le lien fonctionne, sans redirection|Vérifier que le pixel (PageView) se déclenche sur la page",
      adv_lpv_mid_t: "Page un peu lente",
      adv_lpv_mid_w: "Vues de page ÷ clics : {v}.",
      adv_lpv_mid_d: "Alléger la page (images, scripts) et retester la vitesse mobile",
      adv_click_nobuy_t: "Ils cliquent mais n'achètent pas : page ou offre",
      adv_click_nobuy_w: "{c} visites, taux de conversion {v} (objectif ≥ {g} %).",
      adv_click_nobuy_d: "Reprendre en haut de la page la promesse de la publicité (titre, photo, prix)|Photos réelles, avis clients, garantie, livraison et paiement à la livraison bien visibles|Formulaire de commande court (nom, téléphone, wilaya)|Revoir le prix ou proposer un lot / une promotion|Vérifier que l'événement Achat (Purchase) remonte bien",
      adv_cvr_lo_t: "Conversion faible",
      adv_cvr_lo_w: "Taux de conversion {v} sur {c} visites (objectif ≥ {g} %).",
      adv_cvr_lo_d: "Corriger d'abord le visuel (peu de clics, souvent peu qualifiés)|Puis aligner la page sur la promesse de la publicité",
      adv_cvr_mid_t: "Conversion à optimiser",
      adv_cvr_mid_w: "Taux de conversion {v} (objectif ≥ {g} %).",
      adv_cvr_mid_d: "Tester un titre de page plus clair, des avis clients, un formulaire plus court",
      adv_aov_lo_t: "Panier trop petit",
      adv_aov_lo_w: "CPA correct mais ROAS {roas} : panier moyen {aov}.",
      adv_aov_lo_d: "Proposer des lots (2 ou 3 pièces) ou un produit complémentaire|Livraison offerte à partir d'un montant|Mettre en avant l'offre la plus rentable",
      adv_aov_hi_t: "Panier élevé : rentable malgré le CPA",
      adv_aov_hi_w: "Panier moyen {aov}, ROAS {roas}.",
      adv_aov_hi_d: "Garder si la marge suit|Ajuster le CPA cible dans les réglages si besoin",
      adv_scale_campaign_t: "Gagnante : augmenter le budget",
      adv_scale_campaign_w: "{p} achats, CPA {cpa}, ROAS {roas}.",
      adv_scale_campaign_d: "Augmenter le budget de 20 à 30 % tous les 3–4 jours|Déplacer le budget des campagnes perdantes vers celle-ci|Utiliser « ✨ Campagne » pour en créer une variante",
      adv_scale_adset_t: "Gagnant : augmenter ou dupliquer",
      adv_scale_adset_w: "{p} achats, CPA {cpa}, ROAS {roas}.",
      adv_scale_adset_d: "Augmenter le budget de 20 à 30 % tous les 3–4 jours|Dupliquer vers une audience plus large ou un lookalike|Ne pas modifier le ciblage qui fonctionne",
      adv_scale_ad_t: "Gagnante : la décliner",
      adv_scale_ad_w: "{p} achats, CPA {cpa}, ROAS {roas}.",
      adv_scale_ad_d: "Garder cette publicité active|Créer 2 ou 3 variantes (nouvelles accroches, autres visuels, même offre)|La réutiliser dans d'autres ensembles",
      adv_win_wait_t: "Prometteuse, à confirmer",
      adv_win_wait_w: "Seulement {p} achat(s) : pas encore fiable (seuil : {n}).",
      adv_win_wait_d: "Laisser tourner sans modifier jusqu'à {n} achats",
      adv_cut_campaign_t: "Perdante : couper ou corriger",
      adv_cut_campaign_w: "{s} dépensés sans rentabilité.",
      adv_cut_campaign_d: "Mettre en pause ou baisser fortement le budget|Réaffecter le budget aux campagnes gagnantes",
      adv_cut_adset_t: "Perdant : mettre en pause",
      adv_cut_adset_w: "{s} dépensés sans rentabilité.",
      adv_cut_adset_d: "Mettre l'ensemble en pause|Si l'audience est nouvelle, la retester avec la meilleure créa du compte",
      adv_cut_ad_t: "Perdante : mettre en pause",
      adv_cut_ad_w: "{s} dépensés sans rentabilité.",
      adv_cut_ad_d: "Mettre cette publicité en pause|Garder les autres publicités de l'ensemble",
      adv_wait_buy_t: "Pas encore d'achat",
      adv_wait_buy_w: "{s} dépensés, aucune vente pour l'instant.",
      adv_wait_buy_d: "Attendre jusqu'à {k} de dépense avant de couper|Vérifier que le pixel enregistre bien les achats",
      adv_hold_budget_t: "Ne pas augmenter le budget",
      adv_hold_budget_w: "CPA {cpa}, au-dessus de l'objectif ({t}).",
      adv_hold_budget_d: "Corriger d'abord les étapes en rouge ou orange",
    },
    en: {
      sub_readonly: 'Read-only · data from this Ads Manager view', rescan: '↻ Rescan', rescan_t: 'Read the table again',
      full: 'Scan all levels', full_t: 'Scan Campaigns, Ad sets and Ads one after the other', export: '⬇ Excel', export_t: 'Export to Excel',
      propose: '✨ Campaign', propose_t: 'Suggest a campaign from your best KPIs', settings: '⚙ Settings', close_t: 'Close (Esc)',
      date_t: 'Date range (reloads Ads Manager)', custom_range: 'Custom range…', scanned: 'Scanned', not_scanned: 'Not scanned yet',
      profile_t: 'My Stats Meta KPIs profile', launch_t: 'Stats Meta KPIs (Alt+K)',
      lv_campaign: 'Campaigns', lv_adset: 'Ad sets', lv_ad: 'Ads', lv1_campaign: 'campaign', lv1_adset: 'ad set', lv1_ad: 'ad',
      dp_today: 'Today', dp_yesterday: 'Yesterday', dp_last_7d: 'Last 7 days', dp_last_14d: 'Last 14 days', dp_last_30d: 'Last 30 days', dp_this_month: 'This month', dp_last_month: 'Last month',
      v_win: 'Winning', v_opt: 'Optimize', v_lose: 'Losing', v_ins: 'Insufficient data',
      vs_win: 'CPA & ROAS on target', vs_opt: 'Monitor / optimize', vs_lose: 'Losing money', vs_ins: 'Not enough spend / data',
      st_green: 'Good', st_orange: 'Monitor', st_red: 'Investigate', st_gray: 'No data',
      m_roas: 'ROAS', m_ctr: 'Link CTR', m_cpc: 'Link CPC', m_cpm: 'CPM', m_freq: 'Frequency', m_hook: 'Hook rate', m_hold: 'Hold rate', m_lpvr: 'Landing page view rate',
      why_no_spend_col: 'Spend column not in this view', why_below_min: 'Spend {s} is below the {m} minimum', why_lose_zero: '{s} spent, 0 purchases',
      why_no_purchase_yet: 'No purchase yet after {s}', why_cpa_only: 'CPA {c} vs target {t} — ROAS not in view', why_mixed: ' — CPA and ROAS disagree (check basket value)',
      psrc_col: 'Purchases column', psrc_results: 'Results (purchases)', psrc_not_purch: 'Results are not purchases', psrc_no_result: 'No result data', psrc_no_col: 'No purchase column in view', psrc_none: 'No purchase data',
      d_ctr: 'Low link CTR — creative / offer', d_hook: 'Weak hook in the first 3 s', d_cpm: 'Expensive CPM — audience / placement', d_cpc: 'Expensive link clicks',
      d_freq: 'Frequency high — audience fatigue', d_lpv: 'Clicks lost before the page loads', d_hold: 'Viewers drop before ThruPlay',
      try_again: 'Try again', custom_title: 'Custom range', from: 'From', to: 'to', apply_reload: 'Apply (reloads Ads Manager)', cancel: 'Cancel',
      no_data: 'No data yet for this level. Click <b>Rescan</b> to read the table ({lv}).', scan_now: 'Scan now',
      attribution: 'Attribution:', attr_default: 'as set in Ads Manager', rows_n: '{n} rows', table_says: '(table says {n})', scanned_at: 'Scanned {t}',
      date_changed: 'The Ads Manager date range changed since this scan.',
      target_default: 'Target CPA is set to <b>{v}</b> (default). Set your own target so CPA colours and verdicts match your margins.', open_settings: 'Open settings',
      missing_cols: '<b>Not in this Ads Manager view:</b> {l}. Those KPIs show gray — nothing is estimated.', add_cols: 'Add KPI columns…',
      c_purchases: 'Purchases', c_value: 'Purchase value', c_roas: 'ROAS', c_v3s: '3-second video plays', c_thru: 'ThruPlays', c_freq: 'Frequency', c_cpm: 'CPM', c_lpv: 'Landing page views',
      read_partial: 'Read {r} of {e} rows. Rescan to complete.',
      card_spend: 'Amount spent', spend_waste: '{p} on losing / no-purchase rows', card_purch: 'Purchases', purch_na: 'Purchase data not in view',
      purch_excl: 'excl. {n} row(s) not optimizing for purchases', purch_col: 'Purchases column', purch_res: 'from Results (purchases)',
      card_cpa: 'Cost per purchase', target_le: 'target ≤ {v}', card_value: 'Purchase value', not_in_view: 'Not in view', card_roas: 'ROAS', no_value: 'No value tracked', green_ge: 'green ≥ {v}',
      card_ctr: 'Link CTR', ctr_sub: 'link clicks ÷ impressions', card_cpc: 'Link CPC', cpc_sub: 'cost per link click', card_cpm: 'CPM', cpm_sub: 'cost per 1,000 impr.',
      card_freq: 'Frequency (max row)', card_lpv: 'LPV ÷ link clicks', lpv_sub: 'landing page view rate', card_hook: 'Hook rate', hook_sub: '3-s plays ÷ impressions', no_video: 'No video plays',
      card_hold: 'Hold rate', no_thru: 'No ThruPlays', hold_3s: 'ThruPlays ÷ 3-s plays', hold_impr: 'ThruPlays ÷ impressions', na: 'n/a',
      glance: 'At a glance', detail: '{lv} — detail', filter_ph: 'Filter by name…', all: 'All', sort: 'Sort', hide_zero: 'Hide rows with no spend',
      foot_src_col: 'the Purchases column', foot_src_res: 'the Results column when its result type is a purchase',
      foot: 'Verdicts use CPA and ROAS together — never CTR alone. Purchases come from {src}, using the attribution setting selected in Ads Manager; attribution-comparison columns are ignored to avoid double counting. “—” = metric not reported or not applicable; gray = not enough data (spend &lt; {ms} or impressions &lt; {mi}) or column not in this view. Link CTR/CPC are kept separate from CTR/CPC (all). For COD, pixel purchases are orders placed, not confirmed or delivered orders. Totals are summed from the rows; reach and frequency are not additive, so the card shows the highest row frequency.',
      th_verdict: 'Verdict', th_name: 'Name', th_spend: 'Spend', th_purch: 'Purch.', th_cpa: 'CPA', th_value: 'Value', th_roas: 'ROAS', th_ctrLink: 'CTR link', th_cpcLink: 'CPC link', th_cpm: 'CPM',
      th_frequency: 'Freq.', th_lpvRate: 'LPV/click', th_hook: 'Hook', th_hold: 'Hold', th_impressions: 'Impr.', th_reach: 'Reach', th_linkClicks: 'Link clicks', th_lpv: 'LPV', th_v3s: '3-s plays', th_thru: 'ThruPlays', th_ctrAll: 'CTR (all)', th_cpcAll: 'CPC (all)',
      best_tag: '★ BEST', worst_tag: '▼ WORST', no_purch: 'No purchases', insufficient: 'Insufficient data', no_rows: 'No rows match.',
      q1: 'Which campaigns are performing well?', q2: 'Which ad sets need optimization?', q3: 'Which ads are losing money?', q4: 'Which ads deserve further analysis?', q5: 'Where is budget spent inefficiently?',
      level_lbl: '{lv} level', not_scanned_yet: '{lv} not scanned yet.', scan_lv: 'Scan {lv}',
      e1: 'No campaign hits both CPA and ROAS targets in this period.', cpa_roas_na: 'CPA (ROAS n/a)', e2: 'No ad set in the orange zone.', e3: 'No ad is losing money on these thresholds.',
      a4_few: 'Winning on few purchases — confirm with more spend', a4_mixed: 'CPA and ROAS disagree', a4_clicks: 'Strong clicks, weak sales — check landing page / offer', a4_early: 'Early signal: good CTR, little spend', e4: 'Nothing ambiguous right now.',
      of_total: '{p} of {t}', e5: 'No spend on losing or no-purchase rows.',
      set_title: 'Settings & KPI thresholds', set_sub: 'Saved in this browser only. Values are in the account currency ({c}).',
      s_target: 'Target CPA ({c})', s_target_h: 'Green at or below this cost per purchase.', s_tol: 'CPA orange zone (% above target)', s_tol_h: 'Red beyond target × (1 + this %).',
      s_minspend: 'Minimum spend for a verdict ({c})', s_minspend_h: 'Below this → “Insufficient data”.', s_minimpr: 'Minimum impressions for traffic KPIs', s_minimpr_h: 'CTR, CPC, CPM, frequency, hook.',
      s_kill: '“Losing” after spend with 0 purchases (× target CPA)', s_conf: 'Purchases needed to trust a winner', s_hold: 'Hold rate formula', hold_opt1: 'ThruPlays ÷ 3-second plays', hold_opt2: 'ThruPlays ÷ impressions',
      s_color: 'Colour cells in the Ads Manager table', yes: 'On', no: 'Off', s_color_h: 'CTR, CPC, CPM, frequency, CPA, ROAS + verdict bar on names.',
      s_lang: 'Extension language', lang_auto: 'Automatic (Ads Manager language)',
      th_kpi: 'KPI', th_green: 'Green', th_orange: 'Orange', th_red: 'Red', otherwise: 'otherwise', reset_defaults: 'Reset defaults', save: 'Save',
      reading: 'Reading the table…', overlay_note: 'Read-only — the table scrolls briefly behind this panel.', opening_tab: 'Opening the {lv} tab…', reading_lv: 'Reading {lv}…', returning: 'Returning to your tab…',
      err_tab_missing: 'Could not find the {lv} tab.', err_tab_hidden: 'The {lv} tab is not visible.', err_tab_timeout: 'The {lv} tab did not load in time.',
      err_open_table: 'Open the Campaigns, Ad sets or Ads table first.', err_no_table: 'Ads Manager table not found on this page.',
      kpicols_all: 'All KPI columns are already in this view.',
      kpicols_confirm: 'Reload Ads Manager with the KPI columns added to this view?\n\nAdds: purchases, cost per purchase, purchase value, ROAS, 3-second video plays, ThruPlays (and any missing traffic columns).\n\nNothing is edited in your campaigns. Ads Manager may remember this column layout for the view; you can switch back from its Columns menu at any time.',
      bad_range: 'Pick a valid date range.',
      gate_title: 'Sign in to use Stats Meta KPIs', gate_p: 'A free dehini.com account links the extension to your profile: your scans, Excel exports and campaigns show up there.',
      gate_login: 'Sign in', gate_signup: 'Create a free account', gate_hint: 'Already signed in on the site? Open your profile and click “Connect the extension”. This panel updates by itself.',
      gate_note: 'By signing in, you agree that the extension shares with Stats Meta KPIs the statistics it reads (KPIs, breakdowns, ad copy) to power your profile and improve the extension. You can turn this off anytime in your profile.',
      gate_checking: 'Checking your sign-in…', gate_broken: 'The extension is incomplete (its connection module does not answer). Reinstall it from dehini.com/metakpis, then reload Ads Manager.',
      offline: 'Stats Meta KPIs server unreachable: the dashboard works, but sharing, Meta export and campaigns are unavailable for now.',
      share_off: 'Sharing off', my_profile: 'My profile',
      ex_title: 'Export to Excel', ex_table: 'Dashboard (data from the Ads Manager view)', ex_summary: 'Summary', ex_not_scanned: 'not scanned yet — click to scan',
      ex_meta: 'Detailed stats (Meta Ads connection)', ex_placement: 'Placements', ex_age: 'Age', ex_gender: 'Gender', ex_copy: 'Ad copy (headline, text, description)',
      ex_level: 'Break down by', lvl_account: 'Whole account', lvl_campaign: 'Campaign', lvl_adset: 'Ad set', lvl_ad: 'Ad', ex_period: 'Period',
      ex_meta_off: 'Connect Meta Ads in your profile to export placements, ages, genders and ad copy.', connect_meta: 'Connect Meta Ads',
      ex_meta_unconf: 'The Meta Ads connection is not enabled on dehini.com yet.', ex_no_act: 'Open an ad account in Ads Manager (act= in the address).',
      ex_go: 'Download the Excel file', ex_fetch: 'Fetching Meta stats…', ex_done: 'File downloaded ✓ ({n} sheets)', ex_copy_warn: 'ad copy could not be read ({m})', ex_nothing: 'Tick at least one item to export.',
      sh_summary: 'Summary', sh_campaign: 'Campaigns', sh_adset: 'Ad sets', sh_ad: 'Ads', sh_placement: 'Placements', sh_age: 'Age', sh_gender: 'Gender', sh_copy: 'Ad copy',
      x_campaign: 'Campaign', x_adset: 'Ad set', x_ad: 'Ad', x_platform: 'Platform', x_position: 'Position', x_age: 'Age', x_gender: 'Gender',
      x_spend: 'Spend', x_impr: 'Impressions', x_reach: 'Reach', x_freq: 'Frequency', x_clicks: 'Clicks (all)', x_linkclicks: 'Link clicks', x_ctrlink: 'Link CTR (%)', x_cpclink: 'Link CPC',
      x_cpm: 'CPM', x_purch: 'Purchases', x_cpa: 'Cost per purchase', x_value: 'Purchase value', x_roas: 'ROAS', x_lpv: 'Landing page views', x_v3s: '3-s plays', x_thru: 'ThruPlays', x_hook: 'Hook rate (%)', x_hold: 'Hold rate (%)',
      x_verdict: 'Verdict', x_reason: 'Reason', x_delivery: 'Delivery', x_status: 'Status', x_format: 'Format', x_title: 'Headline', x_body: 'Primary text', x_desc: 'Description', x_link: 'Link', x_cta: 'Call to action',
      x_ctrall: 'CTR all (%)', x_cpcall: 'CPC (all)', x_lpvrate: 'LPV ÷ clicks (%)', x_objective: 'Objective', x_ad_id: 'Ad ID',
      g_female: 'Women', g_male: 'Men', g_unknown: 'Unknown', g_all: 'All',
      fm_link: 'Image / link', fm_video: 'Video', fm_carousel: 'Carousel', fm_dynamic: 'Dynamic (several texts)', fm_post: 'Existing post', fm_photo: 'Photo',
      sum_title: 'Stats Meta KPIs — export', sum_account: 'Ad account', sum_period: 'Period', sum_generated: 'Generated', sum_by: 'By', sum_attr: 'Attribution', sum_level: 'Level',
      sum_rows: 'Rows', sum_target: 'Target CPA', sum_meta_note: 'Placements, ages, genders and copy: Meta Marketing API (attribution set in each ad set).', sum_totals: 'Totals — {lv}',
      pr_title: 'Campaign suggested from your best KPIs', pr_loading: 'Analysing your best KPIs ({s} → {u})…', pr_loading_src: 'Reading the source ad set and ad…',
      pr_meta_off: 'Connect Meta Ads in your profile: the extension will read your ages, genders, placements and ad copy, then create the campaign (paused).',
      pr_no_ads: 'No ad spent in this period: pick another date range.', pr_period: 'Analysed period', pr_rerun: 'Run the analysis again',
      pr_sec_campaign: 'Campaign', pr_name: 'Campaign name', pr_budget: 'Daily budget ({c})', pr_budget_h: 'Advantage+ campaign budget, lowest-cost strategy. Suggested: {v}.', pr_objective: 'Objective',
      pr_sec_audience: 'Audience', pr_countries: 'Locations, interests, audiences', pr_countries_h: 'Taken from the source ad set “{n}”.', pr_age: 'Age', pr_to: 'to', pr_gender: 'Gender',
      pr_adv: 'Advantage+ audience (Meta may go beyond the chosen age and gender, which become suggestions)',
      pr_sec_place: 'Placements', pr_place_auto: 'Advantage+ placements (automatic, recommended)', pr_place_manual: 'Manual placements',
      pr_sec_ad: 'Ad', pr_ad_name: 'Ad name', pr_body: 'Primary text', pr_headline: 'Headline', pr_desc: 'Description', pr_link: 'Website URL', pr_cta: 'Call to action',
      pr_not_editable: 'This ad ({f}) doesn\'t allow text changes: the new ad will reuse the same creative, texts included.', pr_visual: 'Same visual as the best ad.',
      pr_why: 'Why these choices', pr_best_ad: 'Best ad', pr_basis_ctr: 'No purchases in this period: picked on link CTR.', pr_src_adset: 'Source ad set',
      pr_kept: 'kept', pr_dropped: 'dropped', pr_little: 'little data', pr_seg: 'Segment',
      pr_age_why: 'Ages kept: {a} (cost per purchase on target or best ROAS).', pr_age_all: 'No clear gap between ages: all kept.',
      pr_gender_all: 'Gap between women and men too small: both kept.', pr_gender_one: '{g} clearly more profitable: targeting narrowed.',
      pr_place_auto_why: 'No placement clearly loses money: automatic placements recommended.', pr_place_manual_why: 'Losing placements dropped: {l}.',
      pr_create: 'Create on Meta (paused)', pr_creating: 'Creating on Meta…', pr_safe: 'Nothing is spent: the campaign is created paused. You can review it in Ads Manager, then publish it.',
      pr_done_t: 'Campaign created, paused ✓', pr_done_p: '“{n}” is in your ad account, paused. Review it, then publish it.', pr_texts_kept: 'Texts kept from the original creative (format can\'t be edited).',
      pr_open: 'Open in Ads Manager', pr_publish: 'Publish now', pr_publish_confirm: 'Publish “{n}”? Delivery starts after Meta\'s review and can spend up to {b} per day.',
      pr_published: 'Campaign published ✓ Delivery starts once Meta has reviewed the ad.', pr_pause: 'Pause again', pr_paused: 'Campaign paused.', pr_profile: 'See in my profile', pr_new: 'New suggestion',
      pr_err_budget: 'Enter a daily budget.', pr_err_age: 'Minimum age must be lower than or equal to maximum age.', pr_err_place: 'Pick at least one placement.', pr_err_name: 'Give the campaign a name.', pr_err_link: 'The link must start with https://',
      pr_years: 'y/o', pr_ads_n: '{n} ads analysed', pr_regions: '{n} region(s)', pr_cities: '{n} city(ies)', pr_auds: '{n} custom audience(s)',
      obj_OUTCOME_SALES: 'Sales', obj_OUTCOME_TRAFFIC: 'Traffic', obj_OUTCOME_LEADS: 'Leads', obj_OUTCOME_ENGAGEMENT: 'Engagement', obj_OUTCOME_AWARENESS: 'Awareness', obj_OUTCOME_APP_PROMOTION: 'App promotion',
      cta_SHOP_NOW: 'Shop now', cta_ORDER_NOW: 'Order now', cta_BUY_NOW: 'Buy now', cta_LEARN_MORE: 'Learn more', cta_SIGN_UP: 'Sign up', cta_CONTACT_US: 'Contact us', cta_GET_OFFER: 'Get offer',
      cta_SEND_MESSAGE: 'Send message', cta_WHATSAPP_MESSAGE: 'Send WhatsApp message', cta_SUBSCRIBE: 'Subscribe', cta_DOWNLOAD: 'Download', cta_APPLY_NOW: 'Apply now', cta_GET_QUOTE: 'Get quote', cta_BOOK_TRAVEL: 'Book now', cta_NO_BUTTON: 'No button',
      pos_facebook_feed: 'Feed', pos_facebook_story: 'Stories', pos_facebook_facebook_reels: 'Reels', pos_facebook_facebook_reels_overlay: 'Reels overlay', pos_facebook_instream_video: 'In-stream videos', pos_facebook_video_feeds: 'Video feeds',
      pos_facebook_marketplace: 'Marketplace', pos_facebook_right_hand_column: 'Right column', pos_facebook_search: 'Search results', pos_facebook_profile_feed: 'Profile feed', pos_facebook_notification: 'Notifications',
      pos_instagram_stream: 'Feed', pos_instagram_story: 'Stories', pos_instagram_reels: 'Reels', pos_instagram_explore: 'Explore', pos_instagram_explore_home: 'Explore home', pos_instagram_profile_feed: 'Profile feed', pos_instagram_profile_reels: 'Profile reels', pos_instagram_ig_search: 'Search',
      pos_audience_network_classic: 'Native, banner, interstitial', pos_audience_network_rewarded_video: 'Rewarded videos', pos_messenger_messenger_home: 'Inbox', pos_messenger_story: 'Stories',
      err_meta_off: 'Meta Ads is not connected: connect it in your profile.', err_meta_expired: 'Meta access expired: reconnect Meta Ads in your profile.', err_meta_unconfigured: 'The Meta Ads connection is not enabled on dehini.com yet.',
      err_meta_error: 'Meta refused: {m}', err_meta_rate: 'Meta is rate-limiting requests: try again in a few minutes.', err_auth: 'Extension session expired: sign in again.',
      err_network: 'Stats Meta KPIs server unreachable.', err_rate: 'Too many requests: wait a little.', err_bad_account: 'Invalid ad account.', err_bad_date: 'Invalid date range.', err_bad_budget: 'Invalid budget.',
      err_bad_placements: 'Pick at least one placement.', err_bad_link: 'Invalid link (https://…).', err_bad_source: 'Source ad can\'t be used.', err_generic: 'Something went wrong.', err_bridge: 'The extension does not answer: reload the page.',
      th_action: "What to do",
      th_cvr: "Conv.",
      m_cvr: "Conversion rate (purchases ÷ landing page views)",
      card_cvr: "Conversion rate",
      cvr_sub: "purchases ÷ landing page views",
      x_cvr: "Conversion rate (%)",
      x_stage: "Step to fix",
      x_action: "Priority action",
      x_comments: "All comments",
      cause: "probable cause: {c}",
      note_adset: "→ set in the ad set.",
      note_ads: "→ see the details at Ads level.",
      funnel: "Funnel:",
      adv_none: "Nothing to report.",
      show_comments: "All comments",
      plan_t: "Action plan — {lv}",
      plan_all: "Show all",
      plan_h: "Rows per step to fix first (for losing rows: the probable cause) and the spend involved. Click to filter the table, then on “What to do” for details.",
      sg_data: "Data",
      sg_learning: "Learning",
      sg_delivery: "Delivery",
      sg_audience: "Targeting",
      sg_hook: "Hook",
      sg_hold: "Retention",
      sg_click: "Click",
      sg_offer: "Offer",
      sg_landing: "Landing page",
      sg_conversion: "Conversion",
      sg_value: "Profitability",
      sg_scale: "Budget",
      sg_cut: "Cut",
      adv_nodata_t: "Not enough data yet",
      adv_nodata_w: "{i} impressions, {s} spent (minimum: {mi} impressions or {ms}).",
      adv_nodata_d: "Let it run without changes|Judge only once the minimum is reached",
      adv_learnlim_t: "Learning limited",
      adv_learnlim_w: "Meta isn't getting enough conversions (≈ 50 a week) to optimize well.",
      adv_learnlim_d: "Merge ad sets that target the same audience|Raise the ad set budget|Or optimize for a more frequent event (add to cart, initiate checkout)",
      adv_learning_t: "In the learning phase",
      adv_learning_w: "Delivery is still stabilizing.",
      adv_learning_d: "Don't change budget, targeting or creatives during learning (≈ 7 days or 50 conversions)",
      adv_cpm_t: "Delivery too expensive (CPM)",
      adv_cpm_w: "CPM {v}, above {w}.",
      adv_cpm_d: "Broaden the audience (Advantage+ audience, fewer exclusions and interests)|Use Advantage+ placements or add Reels and Stories|Check the ad's quality ranking|Avoid several ad sets targeting the same audience",
      adv_freq_hi_t: "Audience fatigue",
      adv_freq_hi_w: "Frequency {v}: the same people see the ad too often.",
      adv_freq_hi_d: "Add 2 or 3 new creatives|Broaden the audience or add a lookalike|Exclude recent buyers",
      adv_freq_mid_t: "Frequency to watch",
      adv_freq_mid_w: "Frequency {v}.",
      adv_freq_mid_d: "Prepare new creatives before fatigue sets in",
      adv_target_t: "Unreceptive targeting",
      adv_target_w: "CPM is low ({cpm}) but link CTR is weak ({ctr}): the ad reaches people who aren't interested.",
      adv_target_d: "Test a more qualified audience: buyer lookalike, interests close to the product|Check age, gender and country (Excel export by age and gender)|Remove low-intent placements (Audience Network)",
      adv_hook_lo_t: "Weak hook (first 3 seconds)",
      adv_hook_lo_w: "Only {v} of people watch 3 seconds (target ≥ {g}%).",
      adv_hook_lo_d: "Show the product or the result in the first second|Add strong on-screen text: problem, promise or price|Open with motion or a face, not a logo|Test 3 different hooks on the same video",
      adv_hook_mid_t: "Hook could be stronger",
      adv_hook_mid_w: "{v} watch 3 seconds (target ≥ {g}%).",
      adv_hook_mid_d: "Test a more direct opening (product, price or result up front)",
      adv_hold_lo_t: "The video loses attention",
      adv_hold_lo_w: "Hold rate {v}: people drop before the end.",
      adv_hold_lo_d: "Cut to 15–20 seconds|Show the benefit and the offer earlier|Faster pace, captions, product close-ups",
      adv_hold_mid_t: "Average retention",
      adv_hold_mid_w: "Hold rate {v}.",
      adv_hold_mid_d: "Cut slow parts and bring the offer forward",
      adv_ctr_lo_t: "Few clicks: creative or message",
      adv_ctr_lo_w: "Link CTR {v} (target ≥ {g}%).",
      adv_ctr_lo_d: "New visual: product in use, before / after, customer reviews|Test another angle: problem, benefit, price, urgency|Put the main benefit in the first line of the text",
      adv_ctr_mid_t: "CTR to improve",
      adv_ctr_mid_w: "Link CTR {v} (target ≥ {g}%).",
      adv_ctr_mid_d: "Test a variant of the visual or of the first line of text",
      adv_watch_noclick_t: "They watch but don't click: the offer",
      adv_watch_noclick_w: "Good hook ({h}) but weak link CTR ({v}): the offer or call to action doesn't convince.",
      adv_watch_noclick_d: "Make the offer clear and visible: price, discount, delivery, cash on delivery|Give a reason to act now: limited stock, promo until a date|Say clearly what to do (“Order now”) with the right button",
      adv_cta_t: "Engagement without link clicks",
      adv_cta_w: "CTR (all) {a} vs link CTR {v}: people react but don't go to the page.",
      adv_cta_d: "Add an explicit call to action in the video and the text|Check the button (Shop now, Order now)|Avoid visuals that invite clicking the image instead of the link",
      adv_lpv_lo_t: "Landing page: lost clicks",
      adv_lpv_lo_w: "{lost} of clicks never reach the page (landing page views ÷ clicks: {v}).",
      adv_lpv_lo_d: "Test mobile speed (target under 3 seconds) and lighten images|Check the link works, with no redirect|Check the pixel (PageView) fires on the page",
      adv_lpv_mid_t: "Page a bit slow",
      adv_lpv_mid_w: "Landing page views ÷ clicks: {v}.",
      adv_lpv_mid_d: "Lighten the page (images, scripts) and retest mobile speed",
      adv_click_nobuy_t: "They click but don't buy: page or offer",
      adv_click_nobuy_w: "{c} visits, conversion rate {v} (target ≥ {g}%).",
      adv_click_nobuy_d: "Repeat the ad's promise at the top of the page (headline, photo, price)|Real photos, customer reviews, guarantee, delivery and cash on delivery clearly visible|Short order form (name, phone, region)|Review the price or offer a bundle / promotion|Check the Purchase event is tracked",
      adv_cvr_lo_t: "Low conversion",
      adv_cvr_lo_w: "Conversion rate {v} over {c} visits (target ≥ {g}%).",
      adv_cvr_lo_d: "Fix the creative first (few clicks, often poorly qualified)|Then align the page with the ad's promise",
      adv_cvr_mid_t: "Conversion to optimize",
      adv_cvr_mid_w: "Conversion rate {v} (target ≥ {g}%).",
      adv_cvr_mid_d: "Test a clearer page headline, customer reviews, a shorter form",
      adv_aov_lo_t: "Basket too small",
      adv_aov_lo_w: "CPA is fine but ROAS is {roas}: average order {aov}.",
      adv_aov_lo_d: "Offer bundles (2 or 3 items) or a complementary product|Free delivery above an amount|Highlight the most profitable offer",
      adv_aov_hi_t: "Large basket: profitable despite the CPA",
      adv_aov_hi_w: "Average order {aov}, ROAS {roas}.",
      adv_aov_hi_d: "Keep it if the margin holds|Adjust the target CPA in settings if needed",
      adv_scale_campaign_t: "Winning: raise the budget",
      adv_scale_campaign_w: "{p} purchases, CPA {cpa}, ROAS {roas}.",
      adv_scale_campaign_d: "Raise the budget by 20–30% every 3–4 days|Move budget from losing campaigns to this one|Use “✨ Campaign” to create a variant",
      adv_scale_adset_t: "Winning: scale or duplicate",
      adv_scale_adset_w: "{p} purchases, CPA {cpa}, ROAS {roas}.",
      adv_scale_adset_d: "Raise the budget by 20–30% every 3–4 days|Duplicate to a broader audience or a lookalike|Don't change the targeting that works",
      adv_scale_ad_t: "Winning: make variations",
      adv_scale_ad_w: "{p} purchases, CPA {cpa}, ROAS {roas}.",
      adv_scale_ad_d: "Keep this ad running|Create 2 or 3 variants (new hooks, other visuals, same offer)|Reuse it in other ad sets",
      adv_win_wait_t: "Promising, to confirm",
      adv_win_wait_w: "Only {p} purchase(s): not reliable yet (threshold: {n}).",
      adv_win_wait_d: "Let it run unchanged until {n} purchases",
      adv_cut_campaign_t: "Losing: cut or fix",
      adv_cut_campaign_w: "{s} spent without profit.",
      adv_cut_campaign_d: "Pause it or cut the budget sharply|Move the budget to winning campaigns",
      adv_cut_adset_t: "Losing: pause it",
      adv_cut_adset_w: "{s} spent without profit.",
      adv_cut_adset_d: "Pause the ad set|If the audience is new, retest it with the account's best creative",
      adv_cut_ad_t: "Losing: pause it",
      adv_cut_ad_w: "{s} spent without profit.",
      adv_cut_ad_d: "Pause this ad|Keep the other ads in the ad set",
      adv_wait_buy_t: "No purchase yet",
      adv_wait_buy_w: "{s} spent, no sale so far.",
      adv_wait_buy_d: "Wait until {k} spend before cutting|Check the pixel records purchases",
      adv_hold_budget_t: "Don't raise the budget",
      adv_hold_budget_w: "CPA {cpa}, above target ({t}).",
      adv_hold_budget_d: "Fix the red or orange steps first",
    },
  };

  // ------------------------------------------------------------------ settings
  const clone = o => JSON.parse(JSON.stringify(o));
  function loadSettings() {
    const s = clone(DEFAULTS);
    try {
      const saved = JSON.parse(localStorage.getItem(LS_KEY) || localStorage.getItem(LS_OLD) || 'null');
      if (saved && typeof saved === 'object') {
        for (const k of Object.keys(DEFAULTS)) if (k !== 't' && saved[k] !== undefined) s[k] = saved[k];
        for (const m of Object.keys(DEFAULTS.t)) if (saved.t && saved.t[m]) Object.assign(s.t[m], saved.t[m]);
      }
    } catch (e) { }
    return s;
  }
  let hasSaved = false;
  try { hasSaved = !!(localStorage.getItem(LS_KEY) || localStorage.getItem(LS_OLD)); } catch (e) { }
  let S = loadSettings();
  function saveSettings() { try { localStorage.setItem(LS_KEY, JSON.stringify(S)); hasSaved = true; } catch (e) { } }

  // ------------------------------------------------------------------ utils
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const frames = () => new Promise(r => { let done = false; const f = () => { if (!done) { done = true; r(); } }; requestAnimationFrame(() => requestAnimationFrame(f)); setTimeout(f, 120); });
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const LANG = document.documentElement.lang || navigator.language || 'en';
  let UI = 'en';
  const pickLang = () => (S.lang === 'fr' || S.lang === 'en') ? S.lang : (/^fr/i.test(LANG) ? 'fr' : 'en');
  UI = pickLang();
  const t = (k, v) => {
    let s = STR[UI][k];
    if (s == null) s = STR.en[k] != null ? STR.en[k] : k;
    if (v) for (const a of Object.keys(v)) s = s.split('{' + a + '}').join(v[a]);
    return s;
  };
  const lvMany = l => t('lv_' + l);
  const COMMA_DEC = /^(fr|de|es|it|pt|nl|tr|ru|pl|id|vi|ar|da|sv|nb|no|fi|cs|ro|hu|el|uk|bg|hr|sk|sl|lt|lv|et|sr|az|kk)/i.test(LANG);
  const nf = (a, b) => { try { return new Intl.NumberFormat(LANG, { minimumFractionDigits: a, maximumFractionDigits: b }); } catch (e) { return new Intl.NumberFormat('en', { minimumFractionDigits: a, maximumFractionDigits: b }); } };
  const NF0 = nf(0, 0), NF1 = nf(1, 1), NF2 = nf(2, 2);
  let CUR = { sym: '$', prefix: false };
  const money = v => v == null || !isFinite(v) ? '—' : (CUR.prefix ? CUR.sym + NF2.format(v) : NF2.format(v) + '\xa0' + CUR.sym);
  const int = v => v == null || !isFinite(v) ? '—' : NF0.format(Math.round(v));
  const pct = (v, d) => v == null || !isFinite(v) ? '—' : (d === 0 ? NF0 : d === 1 ? NF1 : NF2).format(v) + '%';
  const x2 = v => v == null || !isFinite(v) ? '—' : NF2.format(v);
  const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const parseYmd = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const round2 = v => v == null || !isFinite(v) ? null : Math.round(v * 100) / 100;

  function parseNum(line) {
    let x = String(line).replace(/\s/g, '');
    const neg = /^[-−]/.test(x);
    x = x.replace(/[^0-9.,]/g, '');
    if (!/\d/.test(x)) return null;
    const lc = x.lastIndexOf(','), ld = x.lastIndexOf('.');
    const count = ch => x.split(ch).length - 1;
    let out;
    if (lc >= 0 && ld >= 0) { const d = lc > ld ? ',' : '.'; out = x.split(d === ',' ? '.' : ',').join('').replace(d, '.'); }
    else if (lc >= 0) { const dec = count(',') === 1 && (COMMA_DEC || x.length - lc - 1 !== 3); out = dec ? x.replace(',', '.') : x.split(',').join(''); }
    else if (ld >= 0) { const dec = count('.') === 1 && (!COMMA_DEC || x.length - ld - 1 !== 3); out = dec ? x : x.split('.').join(''); }
    else out = x;
    const v = parseFloat(out);
    return isNaN(v) ? null : (neg ? -v : v);
  }
  const cellLines = t => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
  const isDash = l => /^[—–-]$/.test(l);
  const isValLine = l => isDash(l) || (l.length <= 28 && /\d/.test(l) && (l.match(/\p{L}/gu) || []).length <= 4);
  function parseCell(t) {
    if (t === undefined) return { kind: 'missing', lines: [] };
    const lines = cellLines(t);
    const l = lines.find(isValLine);
    if (!l) return { kind: lines.length ? 'text' : 'empty', lines };
    if (isDash(l)) return { kind: 'dash', lines };
    const v = parseNum(l);
    return v == null ? { kind: 'text', lines } : { kind: 'num', v, line: l, lines };
  }

  // ------------------------------------------------------------------ extension bridge (bridge.js, isolated world)
  const pending = new Map();
  let seq = 0;
  function bridge(kind, data, timeout) {
    return new Promise(res => {
      const id = 'q' + (++seq) + '_' + Math.random().toString(36).slice(2, 9);
      const timer = setTimeout(() => { pending.delete(id); res({ error: 'bridge' }); }, timeout || 20000);
      pending.set(id, d => { clearTimeout(timer); res(d || { error: 'generic' }); });
      window.postMessage(Object.assign({ __smk: 'req', id, kind }, data || {}), location.origin);
    });
  }
  window.addEventListener('message', e => {
    if (e.source !== window || !e.data || typeof e.data.__smk !== 'string') return;
    if (e.data.__smk === 'res') { const f = pending.get(e.data.id); if (f) { pending.delete(e.data.id); f(e.data.res); } }
    else if (e.data.__smk === 'auth-changed') refreshAuth(true);
  });
  const api = (action, payload, timeout) => bridge('api', { action, payload: payload || {} }, timeout || 60000);
  const apiErr = d => { const k = 'err_' + ((d && d.error) || 'generic'); return STR[UI][k] ? t(k, { m: (d && d.message) || '' }) : t('err_generic'); };

  const AUTH = { checked: false, connected: false, user: null, meta: null, site: SITE_DEFAULT, broken: false, offline: false, at: 0 };
  async function refreshAuth(force) {
    const st = await bridge('auth', {}, 5000);
    if (st.error === 'bridge') { AUTH.broken = true; AUTH.checked = true; render(); return; }
    AUTH.broken = false;
    const was = AUTH.connected;
    AUTH.connected = !!st.connected;
    AUTH.site = st.site || SITE_DEFAULT;
    if (!AUTH.connected) { AUTH.user = null; AUTH.meta = null; }
    else {
      AUTH.user = AUTH.user || st.user || null;
      // Re-check the account at most once a minute (catches a browser disconnected from the profile).
      if (force || !was || Date.now() - AUTH.at > 60000) {
        const w = await api('whoami', {}, 15000);
        if (w.ok) { AUTH.user = w.user; AUTH.meta = w.meta; AUTH.at = Date.now(); AUTH.offline = false; if (w.site) AUTH.site = w.site; }
        else if (w.error === 'auth') { AUTH.connected = false; AUTH.user = null; }
        else AUTH.offline = true;
      }
    }
    AUTH.checked = true;
    if (state.open) {
      render();
      if (AUTH.connected && !was) { const D = state.levels[state.view]; if (!D || D.date.raw !== urlDate().raw) scan(state.view); }
    }
    updateLauncher();
  }
  const siteUrl = (q, hash) => AUTH.site + (q ? '?' + q : '') + (hash ? '#' + hash : '');
  const openSite = (q, hash) => window.open(siteUrl(q, hash), '_blank', 'noopener');
  const actId = () => { const a = new URL(location.href).searchParams.get('act') || ''; return /^\d{3,20}$/.test(a) ? a : ''; };

  // ------------------------------------------------------------------ Ads Manager page helpers
  const tableEl = () => document.querySelector('[role="table"]');
  const normKey = k => { const m = k.match(/^forAttributionWindow(?:Type)?\((.+),default\)$/); return m ? m[1] : k; };
  function currentLevel() {
    const n = document.querySelector('[data-surface*="/table_cell:forObjectType(name,"]');
    const m = n && n.getAttribute('data-surface').match(/forObjectType\(name,([A-Z_]+)\)/);
    if (m && OBJ2LV[m[1]]) return OBJ2LV[m[1]];
    const p = location.pathname;
    return /\/campaigns/.test(p) ? 'campaign' : /\/adsets/.test(p) ? 'adset' : /\/ads\b/.test(p) ? 'ad' : null;
  }
  function tabEl(level) { return document.querySelector(`[data-surface$="/table_tab:${level}"]`); }
  function tabLabel(level) { const e = tabEl(level); return e ? (e.innerText || '').replace(/\s+/g, ' ').trim() : ''; }
  function urlDate() {
    const u = new URL(location.href);
    const d = u.searchParams.get('date') || u.searchParams.get('insights_date') || '';
    const m = d.match(/(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})(?:,(\w+))?/);
    if (!m) return { raw: d, since: '', until: '', preset: '' };
    return { raw: d, since: m[1], until: ymd(addDays(parseYmd(m[2]), -1)), preset: m[3] || '' };
  }
  /** Period for Meta API calls: the Ads Manager range, otherwise the last 30 full days. */
  function apiRange() {
    const d = urlDate();
    if (d.since && d.until) return { since: d.since, until: d.until };
    const y = addDays(new Date(), -1);
    return { since: ymd(addDays(y, -29)), until: ymd(y) };
  }
  function presetRange(p) {
    const t0 = new Date(); t0.setHours(0, 0, 0, 0);
    switch (p) {
      case 'today': return [t0, addDays(t0, 1)];
      case 'yesterday': return [addDays(t0, -1), t0];
      case 'last_7d': return [addDays(t0, -7), t0];
      case 'last_14d': return [addDays(t0, -14), t0];
      case 'last_30d': return [addDays(t0, -30), t0];
      case 'this_month': return [new Date(t0.getFullYear(), t0.getMonth(), 1), addDays(t0, 1)];
      case 'last_month': return [new Date(t0.getFullYear(), t0.getMonth() - 1, 1), new Date(t0.getFullYear(), t0.getMonth(), 1)];
      default: return null;
    }
  }
  function markReopen() { try { sessionStorage.setItem(SS_REOPEN, JSON.stringify({ level: state.view || currentLevel(), t: Date.now() })); } catch (e) { } }
  function navigateDate(sinceIncl, untilIncl, preset) {
    const val = `${ymd(sinceIncl)}_${ymd(addDays(untilIncl, 1))}` + (preset ? ',' + preset : '');
    const u = new URL(location.href);
    u.searchParams.set('date', val);
    if (u.searchParams.has('insights_date')) u.searchParams.set('insights_date', val);
    markReopen();
    location.assign(u.toString());
  }
  function openKpiColumns() {
    const u = new URL(location.href);
    let cur = (u.searchParams.get('columns') || '').split(',').filter(Boolean);
    if (!cur.length) cur = ['name', 'delivery', 'attribution_setting', 'results', 'cost_per_result'];
    const add = KPI_COLUMNS.filter(c => !cur.includes(c));
    if (!add.length) { alert(t('kpicols_all')); return; }
    if (!confirm(t('kpicols_confirm'))) return;
    u.searchParams.set('columns', cur.concat(add).join(','));
    markReopen();
    location.assign(u.toString());
  }
  async function clickTab(level) {
    const el = tabEl(level);
    if (!el) throw new Error(t('err_tab_missing', { lv: lvMany(level) }));
    const sized = [el, ...el.querySelectorAll('*')].find(e => { const b = e.getBoundingClientRect(); return b.width > 20 && b.height > 10; });
    if (!sized) throw new Error(t('err_tab_hidden', { lv: lvMany(level) }));
    const b = sized.getBoundingClientRect();
    host.style.pointerEvents = 'none';
    const target = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
    host.style.pointerEvents = '';
    (target && sized.contains(target) ? target : sized).click();
  }
  // After the grid has been scrolled programmatically, Ads Manager can keep a freshly opened tab blank until the
  // pointer moves over it. A synthetic hover (no click) wakes it up.
  function pokeTable() {
    const tb = tableEl();
    if (!tb) return;
    const b = tb.getBoundingClientRect(), x = b.x + Math.min(300, b.width / 2), y = b.y + Math.min(120, b.height / 2);
    let el = tb;
    try { host.style.pointerEvents = 'none'; el = document.elementFromPoint(x, y) || tb; } catch (e) { } finally { host.style.pointerEvents = ''; }
    for (const type of ['mouseover', 'mouseenter', 'mousemove']) el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: x, clientY: y, view: window }));
  }
  async function waitReady(level, timeout = 20000) {
    const t0 = Date.now();
    let n = 0;
    while (Date.now() - t0 < timeout) {
      if (++n % 4 === 0) pokeTable();
      if (!level || currentLevel() === level) {
        const names = [...document.querySelectorAll('[data-surface*="/table_cell:forObjectType(name,"]')].filter(e => /table_row:\d/.test(e.getAttribute('data-surface')));
        if (names.some(e => (e.innerText || '').trim())) { await sleep(700); return true; }
        const sum = document.querySelector('[data-surface*="table_row:-1/table_cell:forObjectType(name,"]');
        if (sum && /\b0\b/.test(sum.innerText || '') && Date.now() - t0 > 2500) return true;
      }
      await sleep(250);
    }
    return false;
  }
  function expectedRowCount() {
    const sum = document.querySelector('[data-surface*="table_row:-1/table_cell:forObjectType(name,"]');
    const m = sum && (sum.innerText || '').match(/(\d[\d\s.,]*)/);
    return m ? parseNum(m[1]) : null;
  }

  // ------------------------------------------------------------------ collector (scrolls the grid view, then restores it)
  function findTracks() {
    const tb = tableEl();
    if (!tb) return { t: null };
    let H = null, V = null;
    for (const e of tb.querySelectorAll('[tabindex]')) {
      if (e.children.length !== 1 || e.children[0].children.length !== 0) continue;
      const b = e.getBoundingClientRect(), f = e.children[0].getBoundingClientRect();
      if (!b.width || !b.height) continue;
      if (!H && b.width > 120 && b.height <= 24 && f.width > 0 && f.width < b.width) H = e;
      else if (!V && b.height > 40 && b.width <= 24 && f.height > 0 && f.height < b.height) V = e;
    }
    return { t: tb, H, V };
  }
  function gridScroller(tb) {
    try {
      const c = tb.querySelector('[data-surface*="/table_cell:"]');
      const fk = c && Object.keys(c).find(k => k.startsWith('__reactFiber'));
      let f = fk && c[fk];
      for (let i = 0; i < 80 && f; i++) {
        const sn = f.stateNode, st = sn && sn.state;
        if (st && typeof st === 'object' && typeof st.maxScrollY === 'number' && typeof st.scrollY === 'number') {
          const fn = Object.keys(sn).map(k => sn[k]).find(v => typeof v === 'function' && v.length === 2 && /deltaY/.test(String(v)) && /maxScrollY/.test(String(v)));
          if (fn) return { state: () => sn.state, by: dy => fn(0, dy) };
        }
        f = f.return;
      }
    } catch (e) { }
    return null;
  }
  async function collect(onProgress) {
    const { t: tb, H, V } = findTracks();
    if (!tb) throw new Error(t('err_no_table'));
    const geo = (tr, hz) => { const b = tr.getBoundingClientRect(), f = tr.children[0].getBoundingClientRect(); return hz ? { o: b.x, len: b.width, fl: f.width, st: f.x - b.x, cross: b.y + b.height / 2 } : { o: b.y, len: b.height, fl: f.height, st: f.y - b.y, cross: b.x + b.width / 2 }; };
    const mouse = (type, x, y) => new MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, button: 0, buttons: type === 'mouseup' ? 0 : 1, view: window });
    const setFace = async (tr, hz, s) => {
      if (!tr) return;
      const g = geo(tr, hz);
      const along = Math.max(1, Math.min(g.len - 1, s + g.fl / 2));
      const x = hz ? g.o + along : g.cross, y = hz ? g.cross : g.o + along;
      tr.dispatchEvent(mouse('mousedown', x, y));
      await sleep(15);
      document.dispatchEvent(mouse('mouseup', x, y));
    };
    const plan = (tr, hz) => {
      if (!tr) return [null];
      const g = geo(tr, hz), max = g.len - g.fl - 4;
      if (max <= 4) return [null];
      const step = Math.max(4, g.fl * (hz ? 0.4 : 0.8)), out = []; // only fully visible columns render: overlap generously
      for (let s = 0; s < max; s += step) out.push(s);
      out.push(max + 4);
      return out;
    };
    const data = {}, headers = {};
    // Ads Manager leaves cells blank while the grid is scrolling: wait until the visible metric cells are filled.
    const METRIC = /^(spend|impressions|reach|frequency|cpm|ctr|cpc|clicks|results|cost_per_result)$|^(actions|cost_per_action_type|website_ctr|action_values|website_purchase_roas|purchase_roas|video_thruplay_watched_actions):/;
    const blanks = () => {
      let n = 0;
      tb.querySelectorAll('[data-surface*="/table_cell:"]').forEach(e => {
        const m = e.getAttribute('data-surface').match(/table_row:\d[^/]*\/table_cell:([^/]+)$/);
        if (m && METRIC.test(normKey(m[1])) && !(e.innerText || '').trim()) n++;
      });
      return n;
    };
    const settle = async min => {
      await frames(); await sleep(min);
      let last = -1, same = 0;
      for (let i = 0; i < 16; i++) {
        const b = blanks();
        if (!b) return;
        if (b === last) { if (++same >= 5) return; } else { same = 0; last = b; }
        await sleep(80);
      }
    };
    const grab = () => {
      document.querySelectorAll('[data-surface*="table_column_header:"]').forEach(e => {
        const m = e.getAttribute('data-surface').match(/table_cell:([^/]+)\/table_column_header/);
        const k = m && normKey(m[1]);
        if (k && !headers[k]) headers[k] = (e.innerText || '').replace(/\s+/g, ' ').trim();
      });
      tb.querySelectorAll('[data-surface*="/table_cell:"]').forEach(e => {
        const m = e.getAttribute('data-surface').match(/table_row:([^/]+)\/table_cell:([^/]+)$/);
        if (!m) return;
        const txt = (e.innerText || e.textContent || '').trim();
        const row = data[m[1]] || (data[m[1]] = {}), k = normKey(m[2]);
        if (txt || row[k] === undefined) row[k] = txt;
      });
    };
    const h0 = H ? geo(H, true).st : 0, v0 = V ? geo(V, false).st : 0;
    const ph = plan(H, true);
    const expected = expectedRowCount() || 0;
    const progress = () => { if (onProgress) onProgress(Math.min(0.97, Object.keys(data).length / Math.max(1, expected + 1))); };
    const sweepH = async rev => {
      for (const s of (rev ? ph.slice().reverse() : ph)) {
        if (s !== null) { await setFace(H, true, s); await settle(90); }
        grab();
      }
      progress();
    };
    // Right after a page load Ads Manager fills some figures late: wait (max 8 s) until the visible ones are in.
    for (let i = 0; i < 40 && blanks() > 0; i++) await sleep(200);
    await settle(0);
    const fs = V ? null : gridScroller(tb);
    const y0 = fs ? fs.state().scrollY : 0;
    const pass = async () => {
      if (V) {
        const pv = plan(V, false);
        for (let vi = 0; vi < pv.length; vi++) {
          if (pv[vi] !== null) { await setFace(V, false, pv[vi]); await settle(120); }
          await sweepH(vi % 2 === 1);
        }
      } else if (fs && fs.state().maxScrollY > 0) {
        // No vertical scrollbar in this layout: page through rows with the grid's own scroll handler.
        if (fs.state().scrollY) { fs.by(-fs.state().scrollY); await settle(120); }
        const step = Math.max(46, (fs.state().bodyHeight || 200) * 0.8);
        for (let i = 0; i < 400; i++) {
          await sweepH(i % 2 === 1);
          const y = fs.state().scrollY;
          if (y >= fs.state().maxScrollY - 1) break;
          fs.by(step); await settle(150);
          if (fs.state().scrollY === y) break;
        }
      } else {
        await sweepH(false);
      }
    };
    // Metric cells still blank after the sweep (late-loading figures) → one more pass; filled values are kept.
    const blankValues = () => Object.keys(data).filter(id => /^\d/.test(id)).reduce((n, id) => n + Object.keys(data[id]).filter(k => METRIC.test(k) && data[id][k] === '').length, 0);
    await pass();
    if (blankValues() > 0) await pass();
    if (V) { await setFace(V, false, v0); await frames(); }
    if (fs) { fs.by(y0 - fs.state().scrollY); await frames(); }
    pokeTable();
    grab();
    await setFace(H, true, h0); await frames();
    return { data, headers };
  }

  // ------------------------------------------------------------------ dataset
  function detectCurrency(rows) {
    for (const raw of rows) {
      const c = parseCell(raw.spend);
      if (c.kind === 'num' && c.line) {
        const sym = c.line.replace(/[\d\s.,−-]/g, '');
        if (sym) return { sym, prefix: !/^[\d−-]/.test(c.line.replace(/\s/g, '')) };
      }
    }
    return { sym: '$', prefix: false };
  }
  function buildRow(id, raw, hdr) {
    const keys = Object.keys(raw);
    const P = k => (k ? parseCell(raw[k]) : { kind: 'missing', lines: [] });
    const num = c => (c.kind === 'num' ? c.v : null);
    const first = list => list.find(k => hdr.has(k)) || null;
    const nameKey = keys.find(k => /^forObjectType\(name,/.test(k));
    const delKey = keys.find(k => /^forObjectType\(delivery,/.test(k));
    const nameLines = cellLines(raw[nameKey]);
    const r = { id, name: nameLines[0] || '', delivery: delKey ? (cellLines(raw[delKey])[0] || '') : '', raw };
    const sc = P(hdr.has('spend') ? 'spend' : null), ic = P(hdr.has('impressions') ? 'impressions' : null);
    r.spend = sc.kind === 'num' ? sc.v : sc.kind === 'dash' ? 0 : null;
    r.impressions = ic.kind === 'num' ? ic.v : ic.kind === 'dash' ? 0 : null;
    const delivered = (r.impressions || 0) > 0 || (r.spend || 0) > 0;
    const cnt = c => (c.kind === 'num' ? c.v : c.kind === 'dash' && delivered ? 0 : null);

    r.reach = cnt(P(first(['reach'])));
    r.frequency = num(P(first(['frequency'])));
    r.cpm = num(P(first(['cpm'])));
    r.linkClicks = cnt(P(first(['actions:link_click', 'inline_link_clicks'])));
    r.cpcLink = num(P(first(['cost_per_action_type:link_click', 'cost_per_inline_link_click'])));
    r.ctrLink = num(P(first(['website_ctr:link_click', 'inline_link_click_ctr'])));
    r.ctrAll = num(P(first(['ctr'])));
    r.cpcAll = num(P(first(['cpc'])));
    r.lpv = cnt(P(first(['actions:omni_landing_page_view', 'actions:landing_page_view'])));

    // purchases — selected attribution setting only (attribution-comparison columns are ignored)
    const pAct = PURCHASE_ACTIONS.find(a => hdr.has('actions:' + a));
    r.purchases = null; r.pSrc = ''; r.cpa = null;
    let fromResults = false;
    if (pAct) { r.purchases = cnt(P('actions:' + pAct)); r.pSrc = 'col'; }
    else if (hdr.has('results')) {
      const rc = P('results');
      if (rc.lines.some(l => PURCHASE_RE.test(l))) { r.purchases = cnt(rc); r.pSrc = 'results'; fromResults = true; }
      else r.pSrc = rc.lines.length ? 'not_purch' : 'no_result';
    } else r.pSrc = 'no_col';
    if (pAct && hdr.has('cost_per_action_type:' + pAct)) r.cpa = num(P('cost_per_action_type:' + pAct));
    else if (fromResults && hdr.has('cost_per_result')) r.cpa = num(P('cost_per_result'));
    if (r.cpa == null && r.purchases > 0 && r.spend != null) r.cpa = r.spend / r.purchases;
    if (r.purchases === 0) r.cpa = null;

    const vAct = [pAct].concat(PURCHASE_ACTIONS).filter(Boolean).find(a => hdr.has('action_values:' + a));
    r.value = null;
    if (vAct) { const c = P('action_values:' + vAct); r.value = c.kind === 'num' ? c.v : (c.kind === 'dash' && r.purchases === 0 && delivered ? 0 : null); }
    const kRoas = first(['website_purchase_roas:offsite_conversion.fb_pixel_purchase', 'purchase_roas:omni_purchase']) || [...hdr].find(k => /purchase_roas/.test(k)) || null;
    r.roas = null;
    if (kRoas) { const c = P(kRoas); r.roas = c.kind === 'num' ? c.v : (c.kind === 'dash' && r.purchases === 0 && (r.spend || 0) > 0 ? 0 : null); }
    if (r.roas == null && r.value != null && r.spend > 0) r.roas = r.value / r.spend;

    const k3 = first(['actions:video_view', 'video_3_sec_watched_actions:video_view']) || [...hdr].find(k => /video_3_sec/.test(k)) || null;
    const kT = [...hdr].find(k => /thruplay/i.test(k) && !/^forAttributionWindow/.test(k)) || null;
    r.v3s = k3 ? num(P(k3)) : null;
    r.thru = kT ? num(P(kT)) : null;
    r.hook = r.v3s != null && r.impressions > 0 ? (r.v3s / r.impressions) * 100 : null;
    r.hold = S.holdFormula === 'thru_over_impr'
      ? (r.thru != null && r.impressions > 0 ? (r.thru / r.impressions) * 100 : null)
      : (r.thru != null && r.v3s > 0 ? (r.thru / r.v3s) * 100 : null);
    r.lpvRate = r.lpv != null && r.linkClicks > 0 ? (r.lpv / r.linkClicks) * 100 : null;
    // Conversion rate: purchases ÷ landing page views (link clicks when LPV is not in the view).
    const den = r.lpv > 0 ? r.lpv : r.linkClicks;
    r.cvr = r.purchases != null && den > 0 ? (r.purchases / den) * 100 : null;
    return r;
  }
  function availability(hdr) {
    const has = l => l.some(k => hdr.has(k));
    const pAct = PURCHASE_ACTIONS.some(a => hdr.has('actions:' + a));
    const value = PURCHASE_ACTIONS.some(a => hdr.has('action_values:' + a));
    return {
      spend: hdr.has('spend'), impressions: hdr.has('impressions'), reach: hdr.has('reach'), frequency: hdr.has('frequency'), cpm: hdr.has('cpm'),
      linkClicks: has(['actions:link_click', 'inline_link_clicks']), cpcLink: has(['cost_per_action_type:link_click', 'cost_per_inline_link_click']),
      ctrLink: has(['website_ctr:link_click', 'inline_link_click_ctr']), ctrAll: hdr.has('ctr'), cpcAll: hdr.has('cpc'),
      lpv: has(['actions:omni_landing_page_view', 'actions:landing_page_view']),
      purchases: pAct || hdr.has('results'), purchasesExplicit: pAct, value,
      roas: value || [...hdr].some(k => /purchase_roas/.test(k)),
      v3s: has(['actions:video_view', 'video_3_sec_watched_actions:video_view']) || [...hdr].some(k => /video_3_sec/.test(k)),
      thru: [...hdr].some(k => /thruplay/i.test(k)),
    };
  }
  function buildDataset(level, col) {
    const hdr = new Set(Object.keys(col.headers).filter(k => !/^forAttributionWindow/.test(k)));
    const ids = Object.keys(col.data).filter(id => !id.startsWith('-'));
    const raws = ids.map(id => col.data[id]);
    const cur = detectCurrency(raws);
    CUR = cur;
    const rows = ids.map(id => buildRow(id, col.data[id], hdr)).filter(r => r.name);
    const attrCell = raws.map(r => cellLines(r.attribution_setting)[0]).find(Boolean) || '';
    const ds = {
      level, col, hdr, rows, cur, avail: availability(hdr), attribution: attrCell,
      date: urlDate(), tab: tabLabel(level), scannedAt: new Date(), expected: expectedRowCount(),
      byId: {},
    };
    rows.forEach(r => { ds.byId[r.id] = r; });
    return ds;
  }
  function rebuild() { for (const lv of Object.keys(state.levels)) { const d = state.levels[lv]; state.levels[lv] = Object.assign(buildDataset(lv, d.col), { date: d.date, tab: d.tab, scannedAt: d.scannedAt, expected: d.expected }); } }

  // ------------------------------------------------------------------ scoring
  const trafficOK = r => (r.impressions || 0) >= S.minImpressions;
  const convOK = r => r.spend != null && r.spend >= S.minSpend;
  const holdOK = r => (S.holdFormula === 'thru_over_3s' ? (r.v3s || 0) >= 50 : trafficOK(r));
  function band(m, v) {
    if (v == null || !isFinite(v)) return 'gray';
    const th = S.t[m];
    if (!th) return 'gray';
    if (th.dir === 'up') return v >= th.good ? 'green' : v >= th.warn ? 'orange' : 'red';
    const ok = th.strict ? v < th.good : v <= th.good;
    return ok ? 'green' : v <= th.warn ? 'orange' : 'red';
  }
  function cpaBand(v) {
    if (v == null || !isFinite(v) || !(S.targetCpa > 0)) return 'gray';
    return v <= S.targetCpa ? 'green' : v <= S.targetCpa * (1 + S.cpaTolerancePct / 100) ? 'orange' : 'red';
  }
  function rb(r, m) {
    switch (m) {
      case 'cpa': return convOK(r) ? cpaBand(r.cpa) : 'gray';
      case 'roas': return convOK(r) ? band('roas', r.roas) : 'gray';
      case 'ctr': return trafficOK(r) ? band('ctr', r.ctrLink) : 'gray';
      case 'cpc': return trafficOK(r) ? band('cpc', r.cpcLink) : 'gray';
      case 'cpm': return trafficOK(r) ? band('cpm', r.cpm) : 'gray';
      case 'freq': return trafficOK(r) ? band('freq', r.frequency) : 'gray';
      case 'lpvr': return trafficOK(r) ? band('lpvr', r.lpvRate) : 'gray';
      case 'cvr': return convDataOK(r) ? band('cvr', r.cvr) : 'gray';
      case 'hook': return trafficOK(r) ? band('hook', r.hook) : 'gray';
      case 'hold': return holdOK(r) ? band('hold', r.hold) : 'gray';
      default: return null;
    }
  }
  function verdictOf(r) {
    if (r.spend == null) return { k: 'ins', why: t('why_no_spend_col') };
    if (r.spend < S.minSpend) return { k: 'ins', why: t('why_below_min', { s: money(r.spend), m: money(S.minSpend) }) };
    if (r.purchases == null) return { k: 'ins', why: r.pSrc ? t('psrc_' + r.pSrc) : t('psrc_none') };
    if (r.purchases === 0) {
      return r.spend >= S.killMultiplier * S.targetCpa
        ? { k: 'lose', why: t('why_lose_zero', { s: money(r.spend) }) }
        : { k: 'opt', why: t('why_no_purchase_yet', { s: money(r.spend) }), noPurchase: true };
    }
    const c = cpaBand(r.cpa), ro = r.roas != null ? band('roas', r.roas) : null;
    if (ro == null) {
      return { k: c === 'green' ? 'win' : c === 'red' ? 'lose' : 'opt', why: t('why_cpa_only', { c: money(r.cpa), t: money(S.targetCpa) }), cpaOnly: true };
    }
    const why = `CPA ${money(r.cpa)} · ROAS ${x2(r.roas)}`;
    if (c === 'green' && ro === 'green') return { k: 'win', why };
    if (c === 'red' && ro === 'red') return { k: 'lose', why };
    if ((c === 'red' || ro === 'red') && (c === 'green' || ro === 'green')) return { k: 'opt', why: why + t('why_mixed'), mixed: true };
    if (c === 'red' || ro === 'red') return { k: 'lose', why };
    return { k: 'opt', why };
  }
  function diagOf(r) {
    const out = [];
    if (trafficOK(r)) {
      if (band('ctr', r.ctrLink) === 'red') out.push(t('d_ctr'));
      if (band('hook', r.hook) === 'red') out.push(t('d_hook'));
      if (band('cpm', r.cpm) === 'red') out.push(t('d_cpm'));
      if (band('cpc', r.cpcLink) === 'red') out.push(t('d_cpc'));
      if (band('freq', r.frequency) === 'red') out.push(t('d_freq'));
      if (band('lpvr', r.lpvRate) === 'red') out.push(t('d_lpv'));
    }
    if (holdOK(r) && band('hold', r.hold) === 'red') out.push(t('d_hold'));
    return out;
  }
  function enrich(ds) {
    if (!ds) return [];
    return ds.rows.map(r => { Object.assign(r, { v: verdictOf(r), diag: diagOf(r) }); r.adv = adviceOf(r, ds.level); return r; });
  }

  // ------------------------------------------------------------------ advice: what to do, step by step through the funnel
  // Each row gets comments per funnel step (delivery → hook → retention → click → landing page → conversion → value),
  // plus a budget decision. The primary action is the earliest broken step (fix the top of the funnel first);
  // winners get "scale", losers get "cut" with the probable cause.
  const STAGES = { data: '📊', learning: '⏳', delivery: '💸', audience: '🎯', hook: '🎣', hold: '🎬', click: '👆', offer: '🏷️', landing: '🌐', conversion: '🛒', value: '💰', scale: '🚀', cut: '✂️' };
  const STAGE_ORDER = Object.keys(STAGES);
  const convDen = r => (r.lpv > 0 ? r.lpv : r.linkClicks);
  const convDataOK = r => r.purchases != null && (convDen(r) || 0) >= 40;
  const worst = (...xs) => ['red', 'orange', 'green'].find(c => xs.includes(c)) || 'gray';
  function adviceOf(r, level) {
    const out = [];
    const add = (stage, sev, key, vars) => out.push({ stage, sev, key, vars: vars || {} });
    const good = m => NF1.format(S.t[m].good);
    const v = r.v || verdictOf(r);
    const deliv = String(r.delivery || '');
    if (/apprentissage limit|learning limited|limited learning/i.test(deliv)) add('learning', 'orange', 'learnlim');
    else if (/apprentissage|learning/i.test(deliv)) add('learning', 'gray', 'learning');
    if (r.spend == null || (!trafficOK(r) && !convOK(r))) {
      add('data', 'gray', 'nodata', { i: int(r.impressions), s: money(r.spend), mi: int(S.minImpressions), ms: money(S.minSpend) });
      return finishAdvice(out, v);
    }
    const bt = trafficOK(r) ? band('ctr', r.ctrLink) : 'gray';
    if (trafficOK(r)) {
      const bc = band('cpm', r.cpm), bf = band('freq', r.frequency);
      if (bc === 'red') add('delivery', 'red', 'cpm', { v: money(r.cpm), w: money(S.t.cpm.warn) });
      if (bf === 'red') add('audience', 'red', 'freq_hi', { v: x2(r.frequency) });
      else if (bf === 'orange') add('audience', 'orange', 'freq_mid', { v: x2(r.frequency) });
      if (bc === 'green' && bt === 'red') add('audience', 'orange', 'target', { cpm: money(r.cpm), ctr: pct(r.ctrLink) });
      const bh = r.hook != null ? band('hook', r.hook) : null;
      if (bh === 'red') add('hook', 'red', 'hook_lo', { v: pct(r.hook, 1), g: good('hook') });
      else if (bh === 'orange') add('hook', 'orange', 'hook_mid', { v: pct(r.hook, 1), g: good('hook') });
      if (holdOK(r) && r.hold != null) {
        const bo = band('hold', r.hold);
        if (bo === 'red') add('hold', 'red', 'hold_lo', { v: pct(r.hold, 1) });
        else if (bo === 'orange') add('hold', 'orange', 'hold_mid', { v: pct(r.hold, 1) });
      }
      if (bt === 'red') {
        if (bh && bh !== 'red') add('offer', 'red', 'watch_noclick', { h: pct(r.hook, 1), v: pct(r.ctrLink), g: good('ctr') });
        else add('click', 'red', 'ctr_lo', { v: pct(r.ctrLink), g: good('ctr') });
      } else if (bt === 'orange') add('click', 'orange', 'ctr_mid', { v: pct(r.ctrLink), g: good('ctr') });
      if (r.ctrAll != null && r.ctrLink > 0 && r.ctrAll >= 2 * r.ctrLink && bt !== 'green') add('offer', 'orange', 'cta', { a: pct(r.ctrAll), v: pct(r.ctrLink) });
      if (r.lpvRate != null) {
        const bl = band('lpvr', r.lpvRate);
        if (bl === 'red') add('landing', 'red', 'lpv_lo', { v: pct(r.lpvRate, 0), lost: pct(Math.max(0, 100 - r.lpvRate), 0) });
        else if (bl === 'orange') add('landing', 'orange', 'lpv_mid', { v: pct(r.lpvRate, 0) });
      }
    }
    if (convDataOK(r) && r.cvr != null) {
      const bv = band('cvr', r.cvr), vars = { v: pct(r.cvr), c: int(convDen(r)), g: good('cvr') };
      if (bv === 'red') add('conversion', 'red', bt === 'red' ? 'cvr_lo' : 'click_nobuy', vars);
      else if (bv === 'orange') add('conversion', 'orange', 'cvr_mid', vars);
    }
    if (r.purchases > 0 && convOK(r) && r.value != null && r.roas != null) {
      const cb = cpaBand(r.cpa), br = band('roas', r.roas), aov = money(r.value / r.purchases);
      if (cb !== 'red' && br === 'red') add('value', 'orange', 'aov_lo', { aov, roas: x2(r.roas) });
      else if (cb === 'red' && br === 'green') add('value', 'green', 'aov_hi', { aov, roas: x2(r.roas) });
    }
    const kv = { p: int(r.purchases), cpa: money(r.cpa), roas: r.roas == null ? '—' : x2(r.roas), s: money(r.spend), n: S.minPurchasesConfidence, t: money(S.targetCpa), k: money(S.killMultiplier * S.targetCpa) };
    const lv = LEVELS.includes(level) ? level : 'ad';
    if (v.k === 'win') {
      if (r.purchases >= S.minPurchasesConfidence) add('scale', 'green', 'scale_' + lv, kv); else add('scale', 'gray', 'win_wait', kv);
    } else if (v.k === 'lose') add('cut', 'red', 'cut_' + lv, kv);
    else if (v.noPurchase) add('cut', 'gray', 'wait_buy', kv);
    else if (v.k === 'opt' && r.purchases > 0 && cpaBand(r.cpa) !== 'green') add('scale', 'orange', 'hold_budget', kv);
    return finishAdvice(out, v);
  }
  function finishAdvice(list, v) {
    list.sort((a, b) => STAGE_ORDER.indexOf(a.stage) - STAGE_ORDER.indexOf(b.stage));
    const issues = list.filter(a => a.stage !== 'scale' && a.stage !== 'cut' && (a.sev === 'red' || a.sev === 'orange'));
    const cause = issues.find(a => a.sev === 'red') || issues.find(a => a.sev === 'orange') || null;
    let primary = null;
    if (v.k === 'win') primary = list.find(a => a.stage === 'scale') || null;
    else if (v.k === 'lose') { const c = list.find(a => a.stage === 'cut'); primary = c ? Object.assign({}, c, { cause }) : cause; }
    else primary = cause || list.find(a => a.stage === 'cut' || a.stage === 'scale') || null;
    return { list, primary: primary || list[0] || null };
  }
  const advT = (a, part) => t('adv_' + a.key + '_' + part, a.vars);
  const advTodo = a => advT(a, 'd').split('|').filter(Boolean);
  const advShort = a => !a ? '' : `${STAGES[a.stage]} ${t('sg_' + a.stage)} · ${advT(a, 't')}` + (a.cause ? ' — ' + t('cause', { c: advT(a.cause, 't') }) : '');
  function scopeNote(a, level) {
    if (level === 'ad' && ['delivery', 'audience', 'learning'].includes(a.stage)) return t('note_adset');
    if (level !== 'ad' && ['hook', 'hold', 'click', 'offer', 'landing'].includes(a.stage)) return t('note_ads');
    return '';
  }
  function funnelSteps(r) {
    return [
      ['delivery', trafficOK(r) ? worst(band('cpm', r.cpm), band('freq', r.frequency)) : 'gray'],
      ['hook', rb(r, 'hook')], ['hold', rb(r, 'hold')], ['click', rb(r, 'ctr')], ['landing', rb(r, 'lpvr')],
      ['conversion', rb(r, 'cvr')], ['value', worst(rb(r, 'cpa'), rb(r, 'roas'))],
    ];
  }
  function adviceHtml(r, level) {
    const A = r.adv || adviceOf(r, level);
    const fun = funnelSteps(r).map(([k, c]) => `<span class="fs" style="${cssC(c)}" title="${esc(t('st_' + c))}"><i></i>${esc(t('sg_' + k))}</span>`).join('<b class="fa">›</b>');
    const items = A.list.map(a => {
      const note = scopeNote(a, level);
      return `<li style="${cssC(a.sev)}"><div class="ah"><span class="sgc">${STAGES[a.stage]} ${esc(t('sg_' + a.stage))}</span><b>${esc(advT(a, 't'))}</b></div>
        <div class="aw">${esc(advT(a, 'w'))}${note ? ` <em>${esc(note)}</em>` : ''}</div>
        <ul class="td">${advTodo(a).map(x => `<li>${esc(x)}</li>`).join('')}</ul></li>`;
    }).join('');
    return `<div class="adv"><div class="fun"><span class="fl">${esc(t('funnel'))}</span>${fun}</div><ul class="al">${items || `<li style="${cssC('gray')}"><b>${esc(t('adv_none'))}</b></li>`}</ul></div>`;
  }
  function rankPerf(a, b) {
    const ra = a.roas == null ? -1 : a.roas, rbv = b.roas == null ? -1 : b.roas;
    if (rbv !== ra) return rbv - ra;
    return (a.cpa == null ? 1e12 : a.cpa) - (b.cpa == null ? 1e12 : b.cpa);
  }
  function bestWorst(rows) {
    const best = rows.filter(r => r.v.k === 'win').sort(rankPerf).slice(0, 3).map(r => r.id);
    const worst = rows.filter(r => r.v.k === 'lose').sort((a, b) => (b.spend || 0) - (a.spend || 0)).slice(0, 3).map(r => r.id);
    return { best: new Set(best), worst: new Set(worst) };
  }
  function totalsOf(rows, av) {
    const sum = (f, filter) => { let s = 0, n = 0; for (const r of rows) { if (filter && !filter(r)) continue; const v = f(r); if (v != null && isFinite(v)) { s += v; n++; } } return n ? s : null; };
    const spent = r => (r.spend || 0) > 0;
    const T = {};
    T.spend = av.spend ? sum(r => r.spend) : null;
    T.impressions = av.impressions ? sum(r => r.impressions) : null;
    T.linkClicks = av.linkClicks ? sum(r => r.linkClicks) : null;
    const ratio = (f, g, ok, k) => { const a = sum(f, ok), b = sum(g, ok); return a != null && b > 0 ? (a / b) * k : null; };
    // Rows whose results are not purchases (e.g. view-content campaigns) are left out of purchase totals, never counted as 0.
    const pk = r => r.purchases != null;
    T.purchUnknown = rows.filter(r => spent(r) && !pk(r)).length;
    T.purchases = av.purchases && rows.some(pk) ? sum(r => r.purchases, pk) : null;
    const valKnown = av.value && rows.filter(r => (r.purchases || 0) > 0).every(r => r.value != null);
    T.value = valKnown ? sum(r => r.value, pk) : null;
    T.roas = T.value != null ? ratio(r => r.value, r => r.spend, r => pk(r) && r.spend != null, 1) : null;
    const ci = r => r.linkClicks != null && r.impressions != null;
    const sc = r => r.spend != null && r.linkClicks != null;
    const si = r => r.spend != null && r.impressions != null;
    T.ctrLink = av.linkClicks ? ratio(r => r.linkClicks, r => r.impressions, ci, 100) : null;
    T.cpcLink = av.linkClicks ? ratio(r => r.spend, r => r.linkClicks, sc, 1) : null;
    T.cpm = ratio(r => r.spend, r => r.impressions, si, 1000);
    T.cpa = T.purchases > 0 ? ratio(r => r.spend, r => r.purchases, r => r.spend != null && pk(r), 1) : null;
    const fr = rows.filter(r => r.frequency != null && trafficOK(r)).sort((a, b) => b.frequency - a.frequency)[0];
    T.freqMax = fr ? fr.frequency : null; T.freqMaxName = fr ? fr.name : '';
    const both = r => r.lpv != null && r.linkClicks > 0;
    const lc = sum(r => r.linkClicks, both);
    T.lpvRate = lc > 0 ? (sum(r => r.lpv, both) / lc) * 100 : null;
    const cv = r => r.purchases != null && (convDen(r) || 0) > 0;
    const cden = sum(r => convDen(r), cv);
    T.cvr = cden > 0 ? (sum(r => r.purchases, cv) / cden) * 100 : null;
    const vid = r => r.v3s != null && r.impressions > 0;
    const vi = sum(r => r.impressions, vid);
    T.hook = vi > 0 ? (sum(r => r.v3s, vid) / vi) * 100 : null;
    const th = r => r.thru != null && (S.holdFormula === 'thru_over_3s' ? r.v3s > 0 : r.impressions > 0);
    const den = sum(r => (S.holdFormula === 'thru_over_3s' ? r.v3s : r.impressions), th);
    T.hold = den > 0 ? (sum(r => r.thru, th) / den) * 100 : null;
    T.v3s = sum(r => r.v3s);
    const waste = rows.filter(r => r.v.k === 'lose' || r.v.noPurchase);
    T.waste = sum(r => r.spend, r => waste.includes(r)) || 0;
    T.wastePct = T.spend > 0 ? (T.waste / T.spend) * 100 : null;
    return T;
  }

  // ------------------------------------------------------------------ sharing (only when the account allows it)
  const TOTAL_KEYS = ['spend', 'impressions', 'linkClicks', 'purchases', 'value', 'cpa', 'roas', 'ctrLink', 'cpcLink', 'cpm', 'freqMax', 'lpvRate', 'hook', 'hold', 'waste', 'wastePct'];
  const ROW_KEYS = ['spend', 'impressions', 'reach', 'frequency', 'cpm', 'linkClicks', 'ctrLink', 'cpcLink', 'purchases', 'cpa', 'value', 'roas', 'lpvRate', 'hook', 'hold'];
  function uploadScan(D) {
    if (!AUTH.connected || !AUTH.user || !AUTH.user.share || AUTH.offline) return;
    const rows = enrich(D);
    const T = totalsOf(rows, D.avail);
    const counts = { win: 0, opt: 0, lose: 0, ins: 0 };
    rows.forEach(r => { counts[r.v.k]++; });
    const totals = {};
    TOTAL_KEYS.forEach(k => { totals[k] = round2(T[k]); });
    api('scan', {
      act: actId(), level: D.level, since: D.date.since, until: D.date.until, currency: D.cur.sym, counts, totals,
      rows: rows.slice().sort((a, b) => (b.spend || 0) - (a.spend || 0)).slice(0, 500).map(r => {
        const o = { id: r.id, name: r.name, v: r.v.k };
        ROW_KEYS.forEach(k => { o[k] = round2(r[k]); });
        return o;
      }),
    }, 20000).catch(() => { });
  }

  // ------------------------------------------------------------------ state
  const state = { open: false, levels: {}, view: null, sort: { k: 'spend', dir: -1 }, filter: 'all', q: '', scanning: false, progress: 0, msg: '', error: '', modal: null, showCustom: false, exp: new Set(), expAll: false, stage: '' };
  const EX = { busy: false, msg: '', err: '', parts: { summary: true, campaign: true, adset: true, ad: true, placement: true, age: true, gender: true, copy: true }, level: 'campaign', since: '', until: '' };
  const PR = { step: 'idle', msg: '', err: '', since: '', until: '', exp: null, src: null, an: null, f: null, result: null, busy: false };

  // ------------------------------------------------------------------ UI shell (shadow DOM keeps Ads Manager styles untouched)
  const host = document.createElement('div');
  host.id = 'smk-kpi-host';
  host.style.cssText = 'all:initial;position:fixed;inset:auto;z-index:2147483000;';
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = `<style>${CSS()}</style>
    <button class="launch" data-act="toggle"><span class="lg"><i style="background:${COL.green}"></i><i style="background:${COL.orange}"></i><i style="background:${COL.red}"></i></span><span class="lt">KPI</span><span class="lc"></span></button>
    <aside class="drawer" aria-label="Stats Meta KPIs"><div class="hd"></div><div class="body"></div><div class="ovl"></div></aside>`;
  document.documentElement.appendChild(host);
  const $ = sel => root.querySelector(sel);

  function CSS() {
    return `
:host{all:initial}
*{box-sizing:border-box}
.launch,.drawer{font:13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;color:#101828}
.launch{position:fixed;left:64px;bottom:8px;z-index:2147483000;display:flex;align-items:center;gap:7px;padding:6px 12px 6px 10px;border-radius:999px;background:#0B1B33;color:#fff;font-weight:700;font-size:12px;border:0;cursor:pointer;box-shadow:0 6px 18px rgba(11,27,51,.30)}
.launch:hover{background:#13294b}
.lg{display:flex;gap:3px}.lg i{width:7px;height:7px;border-radius:50%;display:block}
.lc{font-weight:500;color:#C7D2E3;font-size:11px}
.drawer{position:fixed;top:0;right:0;bottom:0;width:min(1200px,100vw);background:#F5F6F8;z-index:2147483001;box-shadow:-14px 0 40px rgba(16,24,40,.22);display:flex;flex-direction:column;transform:translateX(104%);transition:transform .22s ease;visibility:hidden}
.drawer.open{transform:none;visibility:visible}
.hd{background:#0B1B33;color:#fff;padding:9px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:9px}.brand b{font-size:14px;letter-spacing:.01em}.brand span.s{display:block;font-size:10.5px;color:#9FB0C8}
.grow{flex:1}
.seg{display:inline-flex;background:rgba(255,255,255,.08);border-radius:8px;padding:2px;gap:2px}
.seg button{background:transparent;border:0;color:#CBD5E1;padding:5px 10px;border-radius:6px;font:inherit;font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px}
.seg button.on{background:#fff;color:#0B1B33;font-weight:700}
.seg button i{width:6px;height:6px;border-radius:50%;background:#64748B;display:inline-block}
.seg button i.ok{background:#22C55E}
.hd select,.hd .btn{font:inherit;font-size:12px;border-radius:7px;padding:5px 9px;cursor:pointer}
.hd select{background:#13294b;color:#fff;border:1px solid rgba(255,255,255,.22)}
.btn{border:1px solid rgba(255,255,255,.25);background:transparent;color:#fff}
.btn:hover{background:rgba(255,255,255,.08)}
.btn.pri{background:#fff;color:#0B1B33;border-color:#fff;font-weight:700}
.btn.acc{background:#16A34A;border-color:#16A34A;font-weight:700}
.btn.acc:hover{background:#12873d}
.lng{display:inline-flex;border:1px solid rgba(255,255,255,.25);border-radius:7px;overflow:hidden}
.lng button{border:0;background:transparent;color:#CBD5E1;font:inherit;font-size:11px;font-weight:700;padding:5px 7px;cursor:pointer}
.lng button.on{background:#fff;color:#0B1B33}
.me{display:flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.25);background:transparent;color:#fff;border-radius:999px;padding:3px 10px 3px 3px;font:inherit;font-size:12px;cursor:pointer;max-width:180px}
.me .av{width:22px;height:22px;border-radius:50%;background:#fff;color:#0B1B33;display:grid;place-items:center;font-weight:800;font-size:11px;flex:none}
.me span.n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.body{flex:1;overflow:auto;padding:12px 14px 28px;position:relative}
.ctx{display:flex;flex-wrap:wrap;gap:4px 14px;color:#475467;font-size:12px;margin-bottom:10px}
.ctx b{color:#101828;font-weight:600}
.custom{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px;background:#fff;border:1px solid #E4E7EC;border-radius:10px;padding:8px 10px;font-size:12px}
.custom input{font:inherit;border:1px solid #D0D5DD;border-radius:6px;padding:4px 6px}
.ban{display:flex;gap:10px;align-items:center;justify-content:space-between;border-radius:10px;padding:8px 12px;margin-bottom:8px;font-size:12px;border:1px solid}
.ban.info{background:#EFF4FF;border-color:#C7D7FE;color:#1D2939}
.ban.warn{background:#FFFAEB;border-color:#FEDF89;color:#4E1D09}
.ban.err{background:#FEF3F2;border-color:#FECDCA;color:#7A271A}
.ban.ok{background:#ECFDF3;border-color:#ABEFC6;color:#054F31}
.ban button,.lnk{font:inherit;font-size:12px;border:1px solid #D0D5DD;background:#fff;border-radius:7px;padding:4px 10px;cursor:pointer;white-space:nowrap;color:#101828}
.verd{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
.vc{background:#fff;border:1px solid #E4E7EC;border-radius:10px;padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:10px;text-align:left;font:inherit}
.vc.on{outline:2px solid var(--c);outline-offset:-1px}
.vc .n{font-size:24px;font-weight:800;color:var(--c);font-variant-numeric:tabular-nums;min-width:28px}
.vc .t{font-size:12px;font-weight:700;color:#101828}.vc .t small{display:block;font-weight:500;color:#667085;font-size:11px}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:8px;margin-bottom:12px}
.card{background:#fff;border:1px solid #E4E7EC;border-radius:10px;padding:9px 12px 10px 14px;position:relative;overflow:hidden}
.card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c)}
.card .l{font-size:10.5px;color:#667085;text-transform:uppercase;letter-spacing:.05em;font-weight:700}
.card .v{font-size:23px;font-weight:800;font-variant-numeric:tabular-nums;margin-top:3px;color:#101828;white-space:nowrap}
.card .v.g{color:#98A2B3}
.card .s{font-size:11px;color:#667085;margin-top:3px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px;color:var(--c);background:color-mix(in srgb,var(--c) 13%,#fff)}
.pill:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--c)}
h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#475467;margin:14px 0 8px}
.qa{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:8px;margin-bottom:6px}
.q{background:#fff;border:1px solid #E4E7EC;border-radius:10px;padding:10px 12px;border-top:3px solid var(--c)}
.q h4{margin:0;font-size:12.5px;line-height:1.3}.q .lv{font-size:10.5px;color:#667085;margin-top:2px}
.q ul{margin:7px 0 0;padding:0;list-style:none}
.q li{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-top:1px dashed #EAECF0;font-size:12px}
.q li .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.q li .nm small{display:block;color:#667085;font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.q li b{white-space:nowrap;font-variant-numeric:tabular-nums}
.q .empty{color:#667085;font-size:12px;margin-top:6px}
.q .big{font-size:20px;font-weight:800;color:var(--c);margin-top:4px}
.tools{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0 8px}
.tools input[type=search]{font:inherit;font-size:12px;border:1px solid #D0D5DD;border-radius:8px;padding:5px 9px;min-width:190px;background:#fff}
.tools select{font:inherit;font-size:12px;border:1px solid #D0D5DD;border-radius:8px;padding:5px 8px;background:#fff}
.chip{border:1px solid #D0D5DD;background:#fff;padding:4px 10px;border-radius:999px;cursor:pointer;font:inherit;font-size:12px;color:#344054}
.chip.on{border-color:#0B1B33;background:#0B1B33;color:#fff}
.tools label{font-size:12px;color:#475467;display:flex;gap:5px;align-items:center}
.tw{background:#fff;border:1px solid #E4E7EC;border-radius:10px;overflow:auto;max-height:72vh}
table{border-collapse:separate;border-spacing:0;width:100%;font-size:12px;font-variant-numeric:tabular-nums}
th{position:sticky;top:0;background:#F2F4F7;text-align:right;font-weight:700;color:#344054;padding:7px 8px;border-bottom:1px solid #E4E7EC;white-space:nowrap;cursor:pointer;user-select:none;z-index:2;font-size:11.5px}
th.s{color:#0B1B33}th.s:after{content:attr(data-d);margin-left:3px}
th.l,td.l{text-align:left}
.tw th:first-child,.tw td:first-child{position:sticky;left:0;z-index:1;background:inherit}
.tw th:first-child{z-index:3}
td{padding:6px 8px;border-bottom:1px solid #F2F4F7;text-align:right;white-space:nowrap;background:#fff}
td.c{color:var(--c);background:color-mix(in srgb,var(--c) 11%,#fff);font-weight:700}
td.gy{color:#98A2B3}
tr:hover td{filter:brightness(.985)}
.vb{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:11px;color:var(--c)}.vb i{width:9px;height:9px;border-radius:50%;background:var(--c);flex:none}
.nm2{max-width:270px;overflow:hidden;text-overflow:ellipsis;font-weight:600}
.sub{font-size:10.5px;color:#667085;font-weight:400;max-width:270px;overflow:hidden;text-overflow:ellipsis}
.tag{font-size:10px;font-weight:800;border-radius:4px;padding:0 5px;margin-left:5px}
.tag.b{background:#DCFCE7;color:#166534}.tag.w{background:#FEE2E2;color:#991B1B}
.foot{color:#667085;font-size:11px;margin-top:12px;line-height:1.55}
.plan{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:4px}
.pc{display:inline-flex;align-items:center;gap:6px;border:1px solid #E4E7EC;border-left:4px solid var(--c,#D0D5DD);background:#fff;border-radius:9px;padding:6px 10px;font:inherit;font-size:12px;cursor:pointer;color:#101828}
.pc b{font-size:13px}.pc small{color:#667085}.pc.on{outline:2px solid #0B1B33;outline-offset:-1px}
.ph{margin:2px 0 6px;color:#667085;font-size:11px}
.ab{display:inline-flex;align-items:center;gap:6px;max-width:340px;border:1px solid color-mix(in srgb,var(--c) 35%,#fff);background:color-mix(in srgb,var(--c) 9%,#fff);color:#101828;border-radius:8px;padding:4px 8px;font:inherit;font-size:11.5px;cursor:pointer;text-align:left}
.ab{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ab .chev{color:var(--c);font-size:10px}
.tw tr.xp td{position:static;white-space:normal;background:#F9FAFB;padding:0}
.tw tr.xp:hover td{filter:none}
.adv{position:sticky;left:0;max-width:min(1150px,96vw);padding:10px 14px 12px}
.fun{display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-bottom:8px;font-size:11.5px}
.fun .fl{font-weight:700;color:#475467;margin-right:4px}
.fs{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;background:color-mix(in srgb,var(--c) 12%,#fff);color:#101828;font-weight:600}
.fs i{width:8px;height:8px;border-radius:50%;background:var(--c)}.fa{color:#98A2B3;font-weight:400}
.al{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:8px}
.al>li{background:#fff;border:1px solid #E4E7EC;border-left:4px solid var(--c);border-radius:9px;padding:8px 10px}
.ah{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}.ah b{font-size:12.5px}
.sgc{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--c)}
.aw{color:#475467;font-size:11.5px;margin:3px 0 4px}.aw em{color:#7A5AF8;font-style:normal}
.td{margin:0;padding-left:16px;font-size:11.5px;color:#101828}.td li{margin:2px 0}
.ovl{position:absolute;inset:0;display:none;align-items:center;justify-content:center;flex-direction:column;gap:10px;background:rgba(245,246,248,.88);font-weight:700;color:#101828;z-index:5}
.ovl.on{display:flex}
.spin{width:28px;height:28px;border:3px solid #D0D5DD;border-top-color:#0B1B33;border-radius:50%;animation:sp .8s linear infinite}
.bar{width:220px;height:6px;background:#E4E7EC;border-radius:9px;overflow:hidden}.bar i{display:block;height:100%;background:#0B1B33;width:0}
@keyframes sp{to{transform:rotate(360deg)}}
.set{position:absolute;inset:0;background:rgba(16,24,40,.35);display:none;align-items:flex-start;justify-content:center;padding:28px 12px;z-index:6;overflow:auto}
.set.on{display:flex}
.sp{background:#fff;border-radius:12px;width:min(640px,100%);padding:16px 18px;box-shadow:0 20px 50px rgba(16,24,40,.25)}
.sp.wide{width:min(1120px,100%)}
.sp h2{margin:0 0 4px;font-size:16px}.sp>p{margin:0 0 12px;color:#667085;font-size:12px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;margin-bottom:12px}
.f{margin-bottom:10px}
.f label,.f .lb{display:block;font-size:11px;font-weight:700;color:#344054;margin-bottom:3px}
.f input,.f select,.f textarea{width:100%;font:inherit;font-size:13px;border:1px solid #D0D5DD;border-radius:7px;padding:6px 8px;background:#fff;color:#101828}
.f textarea{min-height:92px;resize:vertical;line-height:1.45}
.f input:disabled,.f textarea:disabled,.f select:disabled{background:#F9FAFB;color:#667085}
.f small{color:#667085;font-size:10.5px;display:block;margin-top:2px}
.th{width:100%;border-collapse:collapse;margin-bottom:12px}
.th td,.th th{padding:5px 6px;border-bottom:1px solid #F2F4F7;text-align:left;position:static;background:#fff;font-size:12px;cursor:default}
.th input{width:84px;font:inherit;font-size:12px;border:1px solid #D0D5DD;border-radius:6px;padding:4px 6px}
.sw{display:inline-block;width:10px;height:10px;border-radius:3px;vertical-align:-1px;margin-right:4px}
.acts{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}
.acts .r{display:flex;gap:8px;flex-wrap:wrap}
.b2{font:inherit;font-size:13px;border-radius:8px;padding:7px 14px;cursor:pointer;border:1px solid #D0D5DD;background:#fff;color:#101828;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.b2.pri{background:#0B1B33;border-color:#0B1B33;color:#fff;font-weight:700}
.b2.acc{background:#16A34A;border-color:#16A34A;color:#fff;font-weight:700}
.b2[disabled]{opacity:.55;cursor:default}
.box{border:1px solid #E4E7EC;border-radius:10px;padding:10px 12px;margin-bottom:12px}
.box>h4{margin:0 0 8px;font-size:12.5px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.chk{display:flex;align-items:center;gap:7px;font-size:12.5px;padding:3px 0;cursor:pointer}
.chk input{margin:0;width:15px;height:15px;accent-color:#0B1B33;flex:none}
.chk small{color:#667085}
.chk.dis{color:#98A2B3;cursor:default}
.cols3{display:grid;grid-template-columns:repeat(3,1fr);gap:2px 14px}
.prg{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:16px}
.why{background:#F9FAFB;border:1px solid #E4E7EC;border-radius:10px;padding:10px 12px;font-size:12px}
.why h4{margin:10px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#475467}
.why h4:first-child{margin-top:0}
.why p{margin:4px 0;color:#344054}
.why table{font-size:11.5px;margin-bottom:6px}
.why th{position:static;background:#F2F4F7;cursor:default;padding:4px 6px;font-size:11px}
.why td{padding:4px 6px;background:transparent}
.why tr.k td{background:#ECFDF3}
.why tr.d td{background:#FEF3F2;color:#7A271A}
.st{font-size:10px;font-weight:800;border-radius:4px;padding:0 5px}
.st.k{background:#DCFCE7;color:#166534}.st.d{background:#FEE2E2;color:#991B1B}.st.u{background:#F2F4F7;color:#667085}
.radio{display:flex;gap:14px;flex-wrap:wrap;font-size:12.5px}
.radio label{display:flex;gap:5px;align-items:center;cursor:pointer;font-weight:500;color:#101828;font-size:12.5px;margin:0}
.radio input{margin:0;accent-color:#0B1B33}
.pos{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:6px}
.pos .pg{border:1px solid #EAECF0;border-radius:8px;padding:6px 8px}
.pos .pg b{font-size:11.5px;display:block;margin-bottom:2px}
.pos .chk{font-size:12px;padding:1px 0}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.gate{max-width:560px;margin:40px auto;background:#fff;border:1px solid #E4E7EC;border-radius:16px;padding:28px;text-align:center;box-shadow:0 10px 30px rgba(16,24,40,.08)}
.gate .lg{justify-content:center;margin-bottom:12px}.gate .lg i{width:12px;height:12px}
.gate h2{margin:0 0 8px;font-size:19px}
.gate p{color:#475467;font-size:13px;margin:0 0 16px}
.gate .acts{justify-content:center}
.gate .hint{font-size:12px;color:#667085;margin:16px 0 0}
.gate .note{font-size:11px;color:#98A2B3;margin:12px 0 0;line-height:1.5}
.done{text-align:center;padding:18px 10px}
.done .ic{width:52px;height:52px;border-radius:50%;background:#DCFCE7;color:#16A34A;display:grid;place-items:center;font-size:26px;margin:0 auto 10px}
.done h3{text-transform:none;letter-spacing:0;font-size:17px;color:#101828;margin:0 0 6px}
.done p{color:#475467;margin:0 0 14px}
.done .acts{justify-content:center}
@media (max-width:860px){.verd{grid-template-columns:repeat(2,1fr)}.grid2,.prg,.cols3,.pos,.row2{grid-template-columns:1fr}}
`;
  }

  // ------------------------------------------------------------------ rendering
  function ds() { return state.levels[state.view]; }
  const cssC = c => `--c:${COL[c] || COL.neutral}`;
  const pill = (c, txt) => `<span class="pill" style="${cssC(c)}">${esc(txt)}</span>`;

  function renderHeader() {
    const d = urlDate();
    const langSw = `<span class="lng"><button class="${UI === 'fr' ? 'on' : ''}" data-act="lang:fr">FR</button><button class="${UI === 'en' ? 'on' : ''}" data-act="lang:en">EN</button></span>`;
    const brand = `<div class="brand"><span class="lg"><i style="background:${COL.green}"></i><i style="background:${COL.orange}"></i><i style="background:${COL.red}"></i></span><div><b>Stats Meta KPIs</b><span class="s">${esc(t('sub_readonly'))}</span></div></div>`;
    if (!AUTH.connected) {
      $('.hd').innerHTML = `${brand}<span class="grow"></span>${langSw}<button class="btn" data-act="close" title="${esc(t('close_t'))}">✕</button>`;
      return;
    }
    const lvBtns = LEVELS.map(l => `<button class="${state.view === l ? 'on' : ''}" data-act="level:${l}" title="${esc(state.levels[l] ? t('scanned') : t('not_scanned'))}"><i class="${state.levels[l] ? 'ok' : ''}"></i>${esc(lvMany(l))}</button>`).join('');
    const opts = DATE_PRESETS.map(k => `<option value="${k}" ${d.preset === k ? 'selected' : ''}>${esc(t('dp_' + k))}</option>`).join('')
      + `<option value="__custom" ${d.preset ? '' : 'selected'}>${d.preset || !d.since ? esc(t('custom_range')) : esc(d.since + ' → ' + d.until)}</option>`;
    const u = AUTH.user || {};
    $('.hd').innerHTML = `${brand}
      <div class="seg">${lvBtns}</div>
      <select data-act="date" title="${esc(t('date_t'))}">${opts}</select>
      <span class="grow"></span>
      <button class="btn" data-act="rescan" title="${esc(t('rescan_t'))}">${esc(t('rescan'))}</button>
      <button class="btn" data-act="full" title="${esc(t('full_t'))}">${esc(t('full'))}</button>
      <button class="btn pri" data-act="export" title="${esc(t('export_t'))}">${esc(t('export'))}</button>
      <button class="btn acc" data-act="propose" title="${esc(t('propose_t'))}">${esc(t('propose'))}</button>
      <button class="btn" data-act="settings">${esc(t('settings'))}</button>
      ${langSw}
      <button class="me" data-act="profile" title="${esc(t('profile_t'))}${u.share === false ? ' · ' + esc(t('share_off')) : ''}"><span class="av">${esc((u.name || u.email || '?').trim().charAt(0).toUpperCase())}</span><span class="n">${esc(u.name || t('my_profile'))}</span></button>
      <button class="btn" data-act="close" title="${esc(t('close_t'))}">✕</button>`;
  }

  function renderGate() {
    const body = $('.body');
    if (!AUTH.checked) { body.innerHTML = `<div class="gate"><div class="spin" style="margin:0 auto 12px"></div><p>${esc(t('gate_checking'))}</p></div>`; return; }
    if (AUTH.broken) { body.innerHTML = `<div class="gate"><span class="lg"><i style="background:${COL.green}"></i><i style="background:${COL.orange}"></i><i style="background:${COL.red}"></i></span><p>${esc(t('gate_broken'))}</p></div>`; return; }
    body.innerHTML = `<div class="gate">
      <span class="lg"><i style="background:${COL.green}"></i><i style="background:${COL.orange}"></i><i style="background:${COL.red}"></i></span>
      <h2>${esc(t('gate_title'))}</h2><p>${esc(t('gate_p'))}</p>
      <div class="acts"><button class="b2 pri" data-act="gate:login">${esc(t('gate_login'))}</button><button class="b2" data-act="gate:signup">${esc(t('gate_signup'))}</button></div>
      <p class="hint">${esc(t('gate_hint'))}</p>
      <p class="note">${esc(t('gate_note'))}</p>
    </div>`;
  }

  function renderBody() {
    if (!AUTH.connected) { renderGate(); return; }
    const body = $('.body');
    const D = ds();
    const parts = [];
    if (AUTH.offline) parts.push(`<div class="ban warn"><span>${esc(t('offline'))}</span></div>`);
    if (state.error) parts.push(`<div class="ban err"><span>${esc(state.error)}</span><button data-act="rescan">${esc(t('try_again'))}</button></div>`);
    if (state.showCustom) {
      const d = urlDate();
      parts.push(`<div class="custom"><b>${esc(t('custom_title'))}</b> ${esc(t('from'))} <input type="date" id="cf" value="${esc(d.since)}"> ${esc(t('to'))} <input type="date" id="ct" value="${esc(d.until)}"> <button class="lnk" data-act="customApply">${esc(t('apply_reload'))}</button> <button class="lnk" data-act="customCancel">${esc(t('cancel'))}</button></div>`);
    }
    if (!D) {
      parts.push(`<div class="ban info"><span>${t('no_data', { lv: esc(lvMany(state.view || 'adset')) })}</span><button data-act="rescan">${esc(t('scan_now'))}</button></div>`);
      body.innerHTML = parts.join('');
      return;
    }
    const rows = enrich(D);
    CUR = D.cur;
    const av = D.av = D.avail;
    const nowDate = urlDate();

    parts.push(`<div class="ctx"><span><b>${esc(D.tab || lvMany(D.level))}</b></span><span>📅 <b>${esc(D.date.since || '—')} → ${esc(D.date.until || '—')}</b>${D.date.preset ? ' (' + esc(t('dp_' + D.date.preset) !== 'dp_' + D.date.preset ? t('dp_' + D.date.preset) : D.date.preset.replace(/_/g, ' ')) + ')' : ''}</span><span>${esc(t('attribution'))} <b>${esc(D.attribution || t('attr_default'))}</b></span><span>${esc(t('rows_n', { n: rows.length }))}${D.expected && D.expected !== rows.length ? ` <b style="color:${COL.red}">${esc(t('table_says', { n: D.expected }))}</b>` : ''}</span><span>${esc(t('scanned_at', { t: D.scannedAt.toLocaleTimeString(LANG, { hour: '2-digit', minute: '2-digit' }) }))}</span></div>`);
    if (nowDate.raw !== D.date.raw) parts.push(`<div class="ban warn"><span>${esc(t('date_changed'))}</span><button data-act="rescan">${esc(t('rescan'))}</button></div>`);
    if (!hasSaved) parts.push(`<div class="ban info"><span>${t('target_default', { v: esc(money(S.targetCpa)) })}</span><button data-act="settings">${esc(t('open_settings'))}</button></div>`);
    const missing = [];
    if (!av.purchasesExplicit && !av.purchases) missing.push(t('c_purchases'));
    if (!av.value) missing.push(t('c_value'));
    if (!av.roas) missing.push(t('c_roas'));
    if (!av.v3s) missing.push(t('c_v3s'));
    if (!av.thru) missing.push(t('c_thru'));
    if (!av.frequency) missing.push(t('c_freq'));
    if (!av.cpm) missing.push(t('c_cpm'));
    if (!av.lpv) missing.push(t('c_lpv'));
    if (missing.length) parts.push(`<div class="ban warn"><span>${t('missing_cols', { l: esc(missing.join(', ')) })}</span><button data-act="kpicols">${esc(t('add_cols'))}</button></div>`);
    if (D.expected && rows.length < D.expected) parts.push(`<div class="ban warn"><span>${esc(t('read_partial', { r: rows.length, e: D.expected }))}</span><button data-act="rescan">${esc(t('rescan'))}</button></div>`);

    const counts = { win: 0, opt: 0, lose: 0, ins: 0 };
    rows.forEach(r => { counts[r.v.k]++; });
    parts.push(`<div class="verd">${Object.keys(VERDICT).map(k => `<button class="vc ${state.filter === k ? 'on' : ''}" style="${cssC(VERDICT[k].c)}" data-act="filter:${k}"><span class="n">${counts[k]}</span><span class="t">${esc(t('v_' + k))}<small>${esc(t('vs_' + k))}</small></span></button>`).join('')}</div>`);

    const T = totalsOf(rows, av);
    D.totals = T;
    const card = (label, val, c, sub) => `<div class="card" style="${cssC(c)}"><div class="l">${esc(label)}</div><div class="v ${c === 'gray' ? 'g' : ''}">${val}</div><div class="s">${c && c !== 'neutral' ? pill(c, t('st_' + c)) : ''}${sub ? `<span>${sub}</span>` : ''}</div></div>`;
    const na = esc(t('not_in_view'));
    const cards = [
      card(t('card_spend'), money(T.spend), 'neutral', T.wastePct != null ? esc(t('spend_waste', { p: pct(T.wastePct, 0) })) : ''),
      card(t('card_purch'), T.purchases == null ? '—' : int(T.purchases), 'neutral', esc(T.purchases == null ? t('purch_na') : (T.purchUnknown ? t('purch_excl', { n: T.purchUnknown }) : (av.purchasesExplicit ? t('purch_col') : t('purch_res'))))),
      card(t('card_cpa'), T.purchases === 0 ? 'N/A' : money(T.cpa), T.spend >= S.minSpend ? cpaBand(T.cpa) : 'gray', esc(t('target_le', { v: money(S.targetCpa) }))),
      card(t('card_value'), T.value == null ? '—' : money(T.value), T.value == null ? 'gray' : 'neutral', T.value == null ? na : ''),
      card(t('card_roas'), T.roas == null ? '—' : x2(T.roas), T.spend >= S.minSpend ? band('roas', T.roas) : 'gray', T.roas == null ? (av.roas ? esc(t('no_value')) : na) : esc(t('green_ge', { v: x2(S.t.roas.good) }))),
      card(t('card_ctr'), pct(T.ctrLink), av.ctrLink || av.linkClicks ? band('ctr', T.ctrLink) : 'gray', esc(t('ctr_sub'))),
      card(t('card_cpc'), money(T.cpcLink), band('cpc', T.cpcLink), esc(t('cpc_sub'))),
      card(t('card_cpm'), money(T.cpm), band('cpm', T.cpm), esc(t('cpm_sub'))),
      card(t('card_freq'), T.freqMax == null ? '—' : x2(T.freqMax), band('freq', T.freqMax), T.freqMax == null ? (av.frequency ? esc(t('na')) : na) : esc(T.freqMaxName)),
      card(t('card_lpv'), pct(T.lpvRate, 0), band('lpvr', T.lpvRate), T.lpvRate == null && !av.lpv ? na : esc(t('lpv_sub'))),
      card(t('card_cvr'), pct(T.cvr), T.cvr == null ? 'gray' : band('cvr', T.cvr), T.cvr == null ? na : esc(t('cvr_sub'))),
      card(t('card_hook'), pct(T.hook, 1), band('hook', T.hook), T.hook == null ? (av.v3s ? esc(t('no_video')) : na) : esc(t('hook_sub'))),
      card(t('card_hold'), pct(T.hold, 1), band('hold', T.hold), T.hold == null ? (av.thru ? esc(t('no_thru')) : na) : esc(S.holdFormula === 'thru_over_3s' ? t('hold_3s') : t('hold_impr'))),
    ];
    parts.push(`<div class="cards">${cards.join('')}</div>`);
    parts.push(planHtml(rows, D.level));
    parts.push(`<h3>${esc(t('glance'))}</h3>${renderAnswers()}`);
    const sorts = [['spend', 'th_spend'], ['roas', 'th_roas'], ['cpa', 'th_cpa'], ['ctrLink', 'th_ctrLink'], ['purchases', 'th_purch']];
    parts.push(`<h3>${esc(t('detail', { lv: lvMany(D.level) }))}</h3>
      <div class="tools">
        <input type="search" placeholder="${esc(t('filter_ph'))}" value="${esc(state.q)}" data-act="q">
        ${['all', 'win', 'opt', 'lose', 'ins'].map(k => `<button class="chip ${state.filter === k ? 'on' : ''}" data-act="filter:${k}">${esc(k === 'all' ? t('all') : t('v_' + k))}</button>`).join('')}
        <span class="grow"></span>
        <label>${esc(t('sort'))} <select data-act="sortsel">${sorts.map(([k, l]) => `<option value="${k}" ${state.sort.k === k ? 'selected' : ''}>${esc(t(l))}</option>`).join('')}</select></label>
        <label><input type="checkbox" data-act="hidezero" ${S.hideZeroSpend ? 'checked' : ''}> ${esc(t('hide_zero'))}</label>
        <button class="chip ${state.expAll ? 'on' : ''}" data-act="expAll">💬 ${esc(t('show_comments'))}</button>
      </div>
      <div class="tw"></div>
      <div class="foot">${t('foot', { src: esc(av.purchasesExplicit ? t('foot_src_col') : t('foot_src_res')), ms: esc(money(S.minSpend)), mi: esc(int(S.minImpressions)) })}</div>`);
    body.innerHTML = parts.join('');
    renderTable();
  }


  // Losing rows are grouped by their probable cause (hook, offer, page…): that is what has to be fixed.
  const planKey = r => { const p = r.adv && r.adv.primary; return !p ? '' : p.cause ? p.cause.stage : p.stage; };
  function planHtml(rows, level) {
    const by = {};
    rows.forEach(r => {
      const p = r.adv && r.adv.primary;
      if (!p || (r.spend || 0) <= 0) return;
      const k = planKey(r);
      const o = by[k] || (by[k] = { n: 0, spend: 0, sev: 'gray' });
      o.n++; o.spend += r.spend || 0; o.sev = worst(o.sev, p.sev, p.cause ? 'red' : 'gray');
    });
    const keys = STAGE_ORDER.filter(k => by[k]);
    if (!keys.length) return '';
    const chip = k => `<button class="pc ${state.stage === k ? 'on' : ''}" style="${cssC(by[k].sev)}" data-act="stage:${k}">${STAGES[k]} ${esc(t('sg_' + k))} <b>${by[k].n}</b><small>${esc(money(by[k].spend))}</small></button>`;
    return `<h3>${esc(t('plan_t', { lv: lvMany(level) }))}</h3><div class="plan">${keys.map(chip).join('')}${state.stage ? `<button class="pc" data-act="stage:">${esc(t('plan_all'))}</button>` : ''}</div><p class="ph">${esc(t('plan_h'))}</p>`;
  }

  const TCOLS = [
    { k: 'verdict', l: true }, { k: 'name', l: true }, { k: 'action', l: true },
    { k: 'spend', f: money, need: 'spend' }, { k: 'purchases', th: 'purch', f: int, need: 'purchases' }, { k: 'cpa', f: money, b: 'cpa', need: 'purchases' },
    { k: 'value', f: money, need: 'value' }, { k: 'roas', f: x2, b: 'roas', need: 'roas' }, { k: 'ctrLink', f: v => pct(v), b: 'ctr', need: 'ctrLink' },
    { k: 'cpcLink', f: money, b: 'cpc', need: 'cpcLink' }, { k: 'cpm', f: money, b: 'cpm', need: 'cpm' }, { k: 'frequency', f: x2, b: 'freq', need: 'frequency' },
    { k: 'lpvRate', f: v => pct(v, 0), b: 'lpvr', need: 'lpv' }, { k: 'cvr', f: v => pct(v), b: 'cvr', need: 'purchases' }, { k: 'hook', f: v => pct(v, 1), b: 'hook', need: 'v3s' }, { k: 'hold', f: v => pct(v, 1), b: 'hold', need: 'thru' },
    { k: 'impressions', f: int, need: 'impressions' }, { k: 'reach', f: int, need: 'reach' }, { k: 'linkClicks', f: int, need: 'linkClicks' },
    { k: 'lpv', f: int, need: 'lpv' }, { k: 'v3s', f: int, need: 'v3s' }, { k: 'thru', f: int, need: 'thru' },
    { k: 'ctrAll', f: v => pct(v), need: 'ctrAll' }, { k: 'cpcAll', f: money, need: 'cpcAll' },
  ];
  function renderTable() {
    const D = ds();
    const tw = $('.tw');
    if (!D || !tw) return;
    const av = D.avail;
    const cols = TCOLS.filter(c => !c.need || av[c.need]);
    let rows = D.rows.slice();
    if (S.hideZeroSpend) rows = rows.filter(r => (r.spend || 0) > 0);
    if (state.filter !== 'all') rows = rows.filter(r => r.v.k === state.filter);
    if (state.stage) rows = rows.filter(r => planKey(r) === state.stage);
    if (state.q) { const q = state.q.toLowerCase(); rows = rows.filter(r => r.name.toLowerCase().includes(q)); }
    const { k, dir } = state.sort;
    const val = r => (k === 'verdict' ? VERDICT[r.v.k].order : k === 'name' ? r.name.toLowerCase() : r[k]);
    rows.sort((a, b) => {
      const va = val(a), vb = val(b);
      if (va == null && vb == null) return 0;
      if (va == null) return 1;
      if (vb == null) return -1;
      return (va < vb ? -1 : va > vb ? 1 : 0) * dir;
    });
    const bw = bestWorst(D.rows);
    const head = cols.map(c => `<th class="${c.l ? 'l' : ''} ${state.sort.k === c.k ? 's' : ''}" data-act="sort:${c.k}" data-d="${state.sort.dir > 0 ? '▲' : '▼'}">${esc(t('th_' + (c.th || c.k)))}</th>`).join('');
    const body = rows.map(r => {
      const tds = cols.map(c => {
        if (c.k === 'verdict') return `<td class="l" title="${esc(r.v.why)}"><span class="vb" style="${cssC(VERDICT[r.v.k].c)}"><i></i>${esc(t('v_' + r.v.k))}</span></td>`;
        if (c.k === 'name') {
          const tag = bw.best.has(r.id) ? `<span class="tag b">${esc(t('best_tag'))}</span>` : bw.worst.has(r.id) ? `<span class="tag w">${esc(t('worst_tag'))}</span>` : '';
          const sub = r.v.why || '';
          return `<td class="l" title="${esc(r.name + '\n' + (r.delivery || '') + '\n' + sub)}"><div class="nm2">${esc(r.name)}${tag}</div><div class="sub">${esc(sub)}</div></td>`;
        }
        if (c.k === 'action') {
          const p = r.adv && r.adv.primary, open = state.expAll || state.exp.has(r.id);
          return `<td class="l"><button class="ab" data-act="exp:${esc(r.id)}" style="${cssC(p ? p.sev : 'gray')}" title="${esc(advShort(p))}"><span class="chev">${open ? '▾' : '▸'}</span>${esc(advShort(p) || '—')}</button></td>`;
        }
        const v = r[c.k];
        if (c.k === 'cpa' && r.purchases === 0) return `<td class="gy" title="${esc(t('no_purch'))}">N/A</td>`;
        const txt = c.f(v);
        if (!c.b || v == null) return `<td class="${v == null ? 'gy' : ''}">${txt}</td>`;
        const b = rb(r, c.b);
        if (b === 'gray') return `<td class="gy" title="${esc(t('insufficient'))}">${txt}</td>`;
        return `<td class="c" style="${cssC(b)}">${txt}</td>`;
      }).join('');
      const open = state.expAll || state.exp.has(r.id);
      return `<tr>${tds}</tr>` + (open ? `<tr class="xp"><td class="l" colspan="${cols.length}">${adviceHtml(r, D.level)}</td></tr>` : '');
    }).join('');
    tw.innerHTML = `<table><thead><tr>${head}</tr></thead><tbody>${body || `<tr><td class="l" colspan="${cols.length}" style="color:#667085;padding:14px">${esc(t('no_rows'))}</td></tr>`}</tbody></table>`;
  }

  function renderAnswers() {
    const get = lv => (state.levels[lv] ? enrich(state.levels[lv]) : null);
    const L = { campaign: get('campaign'), adset: get('adset'), ad: get('ad') };
    const fine = L.ad ? 'ad' : L.adset ? 'adset' : L.campaign ? 'campaign' : state.view;
    const li = (r, right, note) => `<li><span class="nm">${esc(r.name)}${note ? `<small>${esc(note)}</small>` : ''}</span><b>${right}</b></li>`;
    const notScanned = lv => `<div class="empty">${esc(t('not_scanned_yet', { lv: lvMany(lv) }))}</div><div style="margin-top:6px"><button class="lnk" data-act="scan:${lv}">${esc(t('scan_lv', { lv: lvMany(lv) }))}</button></div>`;
    const box = (c, title, lv, inner) => `<div class="q" style="${cssC(c)}"><h4>${esc(title)}</h4><div class="lv">${esc(t('level_lbl', { lv: lvMany(lv) }))}</div>${inner}</div>`;
    const spent = r => (r.spend || 0) > 0;
    const out = [];
    {
      const rows = L.campaign;
      let inner;
      if (!rows) inner = notScanned('campaign');
      else {
        const w = rows.filter(r => r.v.k === 'win').sort(rankPerf).slice(0, 4);
        inner = w.length ? `<ul>${w.map(r => li(r, r.roas != null ? x2(r.roas) + '×' : money(r.cpa), r.roas != null ? 'CPA ' + money(r.cpa) : t('cpa_roas_na'))).join('')}</ul>` : `<div class="empty">${esc(t('e1'))}</div>`;
      }
      out.push(box('green', t('q1'), 'campaign', inner));
    }
    {
      const rows = L.adset;
      let inner;
      if (!rows) inner = notScanned('adset');
      else {
        const o = rows.filter(r => r.v.k === 'opt' && spent(r)).sort((a, b) => b.spend - a.spend).slice(0, 4);
        inner = o.length ? `<ul>${o.map(r => li(r, money(r.spend), r.adv && r.adv.primary ? advShort(r.adv.primary) : r.v.why)).join('')}</ul>` : `<div class="empty">${esc(t('e2'))}</div>`;
      }
      out.push(box('orange', t('q2'), 'adset', inner));
    }
    {
      const rows = L.ad;
      let inner;
      if (!rows) inner = notScanned('ad');
      else {
        const l = rows.filter(r => r.v.k === 'lose').sort((a, b) => b.spend - a.spend).slice(0, 4);
        inner = l.length ? `<ul>${l.map(r => li(r, money(r.spend), r.v.why)).join('')}</ul>` : `<div class="empty">${esc(t('e3'))}</div>`;
      }
      out.push(box('red', t('q3'), 'ad', inner));
    }
    {
      const rows = L.ad;
      let inner;
      if (!rows) inner = notScanned('ad');
      else {
        const items = [];
        rows.forEach(r => {
          if (!spent(r)) return;
          if (r.v.k === 'win' && r.purchases < S.minPurchasesConfidence) items.push([r, t('a4_few'), 3]);
          else if (r.v.mixed) items.push([r, t('a4_mixed'), 2]);
          else if ((r.v.k === 'opt' || r.v.k === 'lose') && trafficOK(r) && band('ctr', r.ctrLink) === 'green') items.push([r, t('a4_clicks'), 2]);
          else if (r.v.k === 'ins' && trafficOK(r) && band('ctr', r.ctrLink) === 'green') items.push([r, t('a4_early'), 1]);
        });
        items.sort((a, b) => b[2] - a[2] || (b[0].spend || 0) - (a[0].spend || 0));
        inner = items.length ? `<ul>${items.slice(0, 4).map(([r, why]) => li(r, money(r.spend), why)).join('')}</ul>` : `<div class="empty">${esc(t('e4'))}</div>`;
      }
      out.push(box('orange', t('q4'), 'ad', inner));
    }
    {
      const rows = L[fine];
      let inner;
      if (!rows) inner = notScanned(fine || 'ad');
      else {
        const bad = rows.filter(r => r.v.k === 'lose' || r.v.noPurchase).sort((a, b) => b.spend - a.spend);
        const total = rows.reduce((s, r) => s + (r.spend || 0), 0);
        const waste = bad.reduce((s, r) => s + (r.spend || 0), 0);
        inner = `<div class="big">${money(waste)}${total > 0 ? ` <span style="font-size:12px;color:#667085;font-weight:600">${esc(t('of_total', { p: pct((waste / total) * 100, 0), t: money(total) }))}</span>` : ''}</div>`
          + (bad.length ? `<ul>${bad.slice(0, 3).map(r => li(r, money(r.spend), r.v.why)).join('')}</ul>` : `<div class="empty">${esc(t('e5'))}</div>`);
      }
      out.push(box('red', t('q5'), fine || 'ad', inner));
    }
    return `<div class="qa">${out.join('')}</div>`;
  }

  // ------------------------------------------------------------------ modals (settings · export · proposal)
  function modalEl() {
    let el = $('.set');
    if (!el) { el = document.createElement('div'); el.className = 'set'; $('.drawer').appendChild(el); }
    return el;
  }
  function renderModal() {
    const el = modalEl();
    el.classList.toggle('on', !!state.modal);
    if (!state.modal) { el.innerHTML = ''; return; }
    if (state.modal === 'settings') el.innerHTML = settingsHtml();
    else if (state.modal === 'export') el.innerHTML = exportHtml();
    else if (state.modal === 'propose') el.innerHTML = proposeHtml();
  }
  function closeModal() { state.modal = null; renderModal(); }

  function settingsHtml() {
    const cur = CUR.sym;
    const row = m => {
      const th = S.t[m], meta = TMETA[m];
      const u = meta.unit === 'cur' ? cur : meta.unit;
      const up = th.dir === 'up';
      const g = up ? '≥' : (th.strict ? '<' : '≤');
      return `<tr><td><b>${esc(t('m_' + m))}</b></td>
        <td><span class="sw" style="background:${COL.green}"></span>${g} <input type="number" step="any" data-t="${m}.good" value="${th.good}"> ${esc(u)}</td>
        <td><span class="sw" style="background:${COL.orange}"></span>${up ? '≥' : '≤'} <input type="number" step="any" data-t="${m}.warn" value="${th.warn}"> ${esc(u)}</td>
        <td><span class="sw" style="background:${COL.red}"></span>${esc(t('otherwise'))}</td></tr>`;
    };
    return `<div class="sp">
      <h2>${esc(t('set_title'))}</h2><p>${esc(t('set_sub', { c: cur }))}</p>
      <div class="grid2">
        <div class="f"><label>${esc(t('s_target', { c: cur }))}</label><input type="number" step="any" data-s="targetCpa" value="${S.targetCpa}"><small>${esc(t('s_target_h'))}</small></div>
        <div class="f"><label>${esc(t('s_tol'))}</label><input type="number" step="any" data-s="cpaTolerancePct" value="${S.cpaTolerancePct}"><small>${esc(t('s_tol_h'))}</small></div>
        <div class="f"><label>${esc(t('s_minspend', { c: cur }))}</label><input type="number" step="any" data-s="minSpend" value="${S.minSpend}"><small>${esc(t('s_minspend_h'))}</small></div>
        <div class="f"><label>${esc(t('s_minimpr'))}</label><input type="number" step="1" data-s="minImpressions" value="${S.minImpressions}"><small>${esc(t('s_minimpr_h'))}</small></div>
        <div class="f"><label>${esc(t('s_kill'))}</label><input type="number" step="any" data-s="killMultiplier" value="${S.killMultiplier}"></div>
        <div class="f"><label>${esc(t('s_conf'))}</label><input type="number" step="1" data-s="minPurchasesConfidence" value="${S.minPurchasesConfidence}"></div>
        <div class="f"><label>${esc(t('s_hold'))}</label><select data-s="holdFormula"><option value="thru_over_3s" ${S.holdFormula === 'thru_over_3s' ? 'selected' : ''}>${esc(t('hold_opt1'))}</option><option value="thru_over_impr" ${S.holdFormula === 'thru_over_impr' ? 'selected' : ''}>${esc(t('hold_opt2'))}</option></select></div>
        <div class="f"><label>${esc(t('s_color'))}</label><select data-s="colorNative"><option value="1" ${S.colorNative ? 'selected' : ''}>${esc(t('yes'))}</option><option value="0" ${S.colorNative ? '' : 'selected'}>${esc(t('no'))}</option></select><small>${esc(t('s_color_h'))}</small></div>
        <div class="f"><label>${esc(t('s_lang'))}</label><select data-s="lang"><option value="auto" ${S.lang === 'auto' ? 'selected' : ''}>${esc(t('lang_auto'))}</option><option value="fr" ${S.lang === 'fr' ? 'selected' : ''}>Français</option><option value="en" ${S.lang === 'en' ? 'selected' : ''}>English</option></select></div>
      </div>
      <table class="th"><thead><tr><th>${esc(t('th_kpi'))}</th><th>${esc(t('th_green'))}</th><th>${esc(t('th_orange'))}</th><th>${esc(t('th_red'))}</th></tr></thead><tbody>${Object.keys(TMETA).map(row).join('')}</tbody></table>
      <div class="acts"><button class="b2" data-act="setReset">${esc(t('reset_defaults'))}</button><div class="r"><button class="b2" data-act="modalClose">${esc(t('cancel'))}</button><button class="b2 pri" data-act="setSave">${esc(t('save'))}</button></div></div>
    </div>`;
  }
  function readSettingsForm() {
    const el = modalEl();
    const n = v => { const x = parseFloat(String(v).replace(',', '.')); return isFinite(x) ? x : null; };
    el.querySelectorAll('[data-s]').forEach(i => {
      const k = i.getAttribute('data-s');
      if (k === 'holdFormula') S.holdFormula = i.value;
      else if (k === 'colorNative') S.colorNative = i.value === '1';
      else if (k === 'lang') S.lang = ['fr', 'en'].includes(i.value) ? i.value : 'auto';
      else { const v = n(i.value); if (v != null && v >= 0) S[k] = v; }
    });
    el.querySelectorAll('[data-t]').forEach(i => {
      const [m, f] = i.getAttribute('data-t').split('.');
      const v = n(i.value);
      if (v != null) S.t[m][f] = v;
    });
  }

  // ---------------------------------------------------------- export
  const metaState = () => !AUTH.meta || !AUTH.meta.ready ? 'unconf' : AUTH.meta.connected ? 'ok' : 'off';
  function exportHtml() {
    const ms = metaState();
    const r = apiRange();
    if (!EX.since) { EX.since = r.since; EX.until = r.until; }
    const chk = (k, label, extra, dis) => `<label class="chk ${dis ? 'dis' : ''}"><input type="checkbox" data-ex="${k}" ${EX.parts[k] && !dis ? 'checked' : ''} ${dis ? 'disabled' : ''}> <span>${esc(label)}${extra ? ` <small>${esc(extra)}</small>` : ''}</span></label>`;
    const lvRows = LEVELS.map(l => {
      const D = state.levels[l];
      return D ? chk(l, lvMany(l), t('rows_n', { n: D.rows.length })) : `<div class="chk dis"><input type="checkbox" disabled> <span>${esc(lvMany(l))} <small>·</small> <button class="lnk" data-act="scan:${l}">${esc(t('ex_not_scanned'))}</button></span></div>`;
    }).join('');
    const metaDis = ms !== 'ok' || !actId();
    const metaMsg = ms === 'unconf' ? `<div class="ban warn"><span>${esc(t('ex_meta_unconf'))}</span></div>`
      : ms === 'off' ? `<div class="ban info"><span>${esc(t('ex_meta_off'))}</span><button data-act="metaConnect">${esc(t('connect_meta'))}</button></div>`
        : !actId() ? `<div class="ban warn"><span>${esc(t('ex_no_act'))}</span></div>` : '';
    return `<div class="sp">
      <h2>${esc(t('ex_title'))}</h2><p>${esc(t('sum_account'))} : <b>${esc(actId() ? 'act_' + actId() : '—')}</b></p>
      <div class="box"><h4>${esc(t('ex_table'))}</h4>${chk('summary', t('ex_summary'))}${lvRows}</div>
      <div class="box"><h4>${esc(t('ex_meta'))}</h4>${metaMsg}
        <div class="cols3">${chk('placement', t('ex_placement'), '', metaDis)}${chk('age', t('ex_age'), '', metaDis)}${chk('gender', t('ex_gender'), '', metaDis)}</div>
        ${chk('copy', t('ex_copy'), '', metaDis)}
        <div class="row2" style="margin-top:8px">
          <div class="f"><label>${esc(t('ex_level'))}</label><select data-exl="level" ${metaDis ? 'disabled' : ''}>${['account', 'campaign', 'adset', 'ad'].map(l => `<option value="${l}" ${EX.level === l ? 'selected' : ''}>${esc(t('lvl_' + l))}</option>`).join('')}</select></div>
          <div class="f"><label>${esc(t('ex_period'))}</label><div style="display:flex;gap:6px;align-items:center"><input type="date" data-exl="since" value="${esc(EX.since)}" ${metaDis ? 'disabled' : ''}> → <input type="date" data-exl="until" value="${esc(EX.until)}" ${metaDis ? 'disabled' : ''}></div></div>
        </div>
      </div>
      ${EX.err ? `<div class="ban err"><span>${esc(EX.err)}</span></div>` : ''}
      ${EX.msg ? `<div class="ban ${EX.busy ? 'info' : 'ok'}"><span>${EX.busy ? '<span class="spin" style="display:inline-block;width:12px;height:12px;border-width:2px;vertical-align:-2px;margin-right:6px"></span>' : ''}${esc(EX.msg)}</span></div>` : ''}
      <div class="acts"><span></span><div class="r"><button class="b2" data-act="modalClose">${esc(t('cancel'))}</button><button class="b2 pri" data-act="exportGo" ${EX.busy ? 'disabled' : ''}>${esc(t('ex_go'))}</button></div></div>
    </div>`;
  }
  const verdictLabel = r => t('v_' + verdictOf(r).k);
  const genderLbl = g => t('g_' + (g === 'female' || g === 'male' ? g : 'unknown'));
  const posLabel = (plat, pos) => {
    const m = POS_MAP[plat] && POS_MAP[plat][pos];
    const k = m ? 'pos_' + plat + '_' + m : '';
    return k && STR[UI][k] ? t(k) : String(pos || '').replace(/_/g, ' ');
  };
  const metaRow = r => ({ spend: r.spend, purchases: r.purchases, cpa: r.cpa, roas: r.roas, pSrc: 'col' });
  const KPI_X = [
    { h: 'x_spend', f: 'money', k: 'spend' }, { h: 'x_impr', f: 'int', k: 'impressions' }, { h: 'x_reach', f: 'int', k: 'reach' }, { h: 'x_freq', f: 'dec', k: 'frequency' },
    { h: 'x_linkclicks', f: 'int', k: 'link_clicks' }, { h: 'x_ctrlink', f: 'pct', k: 'ctr_link' }, { h: 'x_cpclink', f: 'money', k: 'cpc_link' }, { h: 'x_cpm', f: 'money', k: 'cpm' },
    { h: 'x_purch', f: 'int', k: 'purchases' }, { h: 'x_cpa', f: 'money', k: 'cpa' }, { h: 'x_value', f: 'money', k: 'value' }, { h: 'x_roas', f: 'dec', k: 'roas' },
    { h: 'x_lpv', f: 'int', k: 'lpv' }, { h: 'x_v3s', f: 'int', k: 'v3s' }, { h: 'x_thru', f: 'int', k: 'thru' }, { h: 'x_hook', f: 'pct', k: 'hook' }, { h: 'x_hold', f: 'pct', k: 'hold' },
  ];
  const idCols = level => (level === 'account' ? [] : level === 'campaign' ? [['x_campaign', 'campaign', 32]] : level === 'adset' ? [['x_campaign', 'campaign', 28], ['x_adset', 'adset', 28]] : [['x_campaign', 'campaign', 26], ['x_adset', 'adset', 26], ['x_ad', 'ad', 26]]);
  function breakdownSheet(name, rows, level, segCols) {
    const ids = idCols(level);
    const cols = ids.map(([h, , w]) => ({ h: t(h), w })).concat(segCols.map(([h, , w]) => ({ h: t(h), w: w || 16 })), KPI_X.map(c => ({ h: t(c.h), f: c.f, w: 13 })), [{ h: t('x_verdict'), w: 18 }]);
    return {
      name, cols,
      rows: rows.map(r => ids.map(([, k]) => r[k]).concat(segCols.map(([, fn]) => fn(r)), KPI_X.map(c => (r[c.k] == null ? null : Number(r[c.k]))), [verdictLabel(metaRow(r))])),
    };
  }
  function copySheet(rows) {
    const join = (arr, one) => (arr && arr.length > 1 ? arr.join(' | ') : one || (arr && arr[0]) || '');
    const cols = [{ h: t('x_campaign'), w: 26 }, { h: t('x_adset'), w: 24 }, { h: t('x_ad'), w: 26 }, { h: t('x_ad_id'), w: 18 }, { h: t('x_status'), w: 12 }, { h: t('x_format'), w: 16 },
    { h: t('x_title'), f: 'wrap', w: 30 }, { h: t('x_body'), f: 'wrap', w: 60 }, { h: t('x_desc'), f: 'wrap', w: 30 }, { h: t('x_link'), w: 36 }, { h: t('x_cta'), w: 16 },
    { h: t('x_spend'), f: 'money' }, { h: t('x_impr'), f: 'int' }, { h: t('x_ctrlink'), f: 'pct' }, { h: t('x_cpm'), f: 'money' }, { h: t('x_purch'), f: 'int' }, { h: t('x_cpa'), f: 'money' },
    { h: t('x_value'), f: 'money' }, { h: t('x_roas'), f: 'dec' }, { h: t('x_hook'), f: 'pct' }, { h: t('x_verdict'), w: 18 }];
    return {
      name: t('sh_copy'), cols,
      rows: rows.map(r => {
        const x = r.text || {}, v = x.variants || {};
        return [r.campaign, r.adset, r.ad, r.ad_id, r.status, t('fm_' + (x.mode || 'post')), join(v.title, x.title), join(v.body, x.body), join(v.description, x.description), x.link || '',
          x.cta ? (STR[UI]['cta_' + x.cta] ? t('cta_' + x.cta) : x.cta) : '', r.spend, r.impressions, r.ctr_link, r.cpm, r.purchases, r.cpa, r.value, r.roas, r.hook, verdictLabel(metaRow(r))];
      }),
    };
  }
  function levelSheet(D) {
    const rows = enrich(D);
    const C = [['x_verdict', r => t('v_' + r.v.k), 'text', 16], ['x_campaign', r => r.name, 'text', 34], ['x_delivery', r => r.delivery, 'text', 14],
    ['x_spend', r => r.spend, 'money'], ['x_purch', r => r.purchases, 'int'], ['x_cpa', r => r.purchases === 0 ? null : r.cpa, 'money'], ['x_value', r => r.value, 'money'], ['x_roas', r => r.roas, 'dec'],
    ['x_ctrlink', r => r.ctrLink, 'pct'], ['x_cpclink', r => r.cpcLink, 'money'], ['x_cpm', r => r.cpm, 'money'], ['x_freq', r => r.frequency, 'dec'], ['x_lpvrate', r => r.lpvRate, 'pct'], ['x_cvr', r => r.cvr, 'pct'],
    ['x_hook', r => r.hook, 'pct'], ['x_hold', r => r.hold, 'pct'], ['x_impr', r => r.impressions, 'int'], ['x_reach', r => r.reach, 'int'], ['x_linkclicks', r => r.linkClicks, 'int'],
    ['x_lpv', r => r.lpv, 'int'], ['x_v3s', r => r.v3s, 'int'], ['x_thru', r => r.thru, 'int'], ['x_ctrall', r => r.ctrAll, 'pct'], ['x_cpcall', r => r.cpcAll, 'money'],
    ['x_reason', r => r.v.why, 'text', 40],
    ['x_stage', r => planKey(r) ? t('sg_' + planKey(r)) : '', 'text', 16],
    ['x_action', r => r.adv && r.adv.primary ? advT(r.adv.primary, 't') + (r.adv.primary.cause ? ' — ' + t('cause', { c: advT(r.adv.primary.cause, 't') }) : '') : '', 'wrap', 40],
    ['x_comments', r => (r.adv ? r.adv.list : []).map(a => `[${t('sg_' + a.stage)}] ${advT(a, 't')} — ${advT(a, 'w')}\n→ ${advTodo(a).join(' ; ')}`).join('\n'), 'wrap', 90]];
    const nameH = { campaign: 'x_campaign', adset: 'x_adset', ad: 'x_ad' }[D.level];
    return {
      name: t('sh_' + D.level),
      cols: C.map(([h, , f, w], i) => ({ h: t(i === 1 ? nameH : h), f, w: w || 13 })),
      rows: rows.slice().sort((a, b) => (b.spend || 0) - (a.spend || 0)).map(r => C.map(([, fn]) => { const v = fn(r); return typeof v === 'number' && !isFinite(v) ? null : v; })),
    };
  }
  async function runExport() {
    if (EX.busy) return;
    EX.err = ''; EX.msg = '';
    const metaParts = ['placement', 'age', 'gender', 'copy'].filter(k => EX.parts[k]);
    const useMeta = metaState() === 'ok' && actId() && metaParts.length;
    const scanned = LEVELS.filter(l => EX.parts[l] && state.levels[l]);
    if (!EX.parts.summary && !scanned.length && !useMeta) { EX.err = t('ex_nothing'); renderModal(); return; }
    EX.busy = true;
    const sheets = [];
    let meta = null;
    try {
      if (useMeta) {
        if (!EX.since || !EX.until || EX.since > EX.until) throw new Error(t('bad_range'));
        EX.msg = t('ex_fetch'); renderModal();
        const d = await api('export', { act: actId(), since: EX.since, until: EX.until, level: EX.level, parts: metaParts }, 240000);
        if (!d.ok) throw new Error(apiErr(d));
        meta = d;
      }
      const D0 = state.levels[state.view] || state.levels[scanned[0]];
      if (EX.parts.summary) {
        const pre = [[t('sum_title')], [], [t('sum_account'), actId() ? 'act_' + actId() + (meta && meta.account && meta.account.name ? ' · ' + meta.account.name : '') : '—'],
        [t('sum_period'), D0 ? (D0.date.since || '—') + ' → ' + (D0.date.until || '—') : (meta ? meta.since + ' → ' + meta.until : '—')],
        [t('sum_generated'), new Date().toLocaleString(LANG)], [t('sum_by'), AUTH.user ? AUTH.user.name + ' · ' + AUTH.user.email : ''],
        [t('sum_attr'), D0 ? D0.attribution || t('attr_default') : ''], [t('sum_target'), { v: S.targetCpa, s: 'money' }]];
        scanned.forEach(l => {
          const D = state.levels[l], rows = enrich(D), T = totalsOf(rows, D.avail);
          const c = { win: 0, opt: 0, lose: 0, ins: 0 }; rows.forEach(r => { c[r.v.k]++; });
          pre.push([], [{ v: t('sum_totals', { lv: lvMany(l) }), s: 'bold' }],
            [t('sum_rows'), rows.length], [t('x_spend'), { v: round2(T.spend), s: 'money' }], [t('x_purch'), T.purchases], [t('x_cpa'), { v: round2(T.cpa), s: 'money' }],
            [t('x_value'), { v: round2(T.value), s: 'money' }], [t('x_roas'), { v: round2(T.roas), s: 'dec' }], [t('x_ctrlink'), { v: round2(T.ctrLink), s: 'dec' }],
            [t('x_cpclink'), { v: round2(T.cpcLink), s: 'money' }], [t('x_cpm'), { v: round2(T.cpm), s: 'money' }],
            [t('v_win'), c.win], [t('v_opt'), c.opt], [t('v_lose'), c.lose], [t('v_ins'), c.ins]);
        });
        if (meta) pre.push([], [t('sum_meta_note')], [t('ex_period'), meta.since + ' → ' + meta.until], [t('ex_level'), t('lvl_' + meta.level)]);
        sheets.push({ name: t('sh_summary'), pre, widths: [34, 46] });
      }
      scanned.forEach(l => sheets.push(levelSheet(state.levels[l])));
      if (meta) {
        const P = meta.parts || {};
        if (P.placement) sheets.push(breakdownSheet(t('sh_placement'), P.placement, meta.level, [['x_platform', r => PLATFORM_NAME[r.publisher_platform] || r.publisher_platform], ['x_position', r => posLabel(r.publisher_platform, r.platform_position), 22]]));
        if (P.age) sheets.push(breakdownSheet(t('sh_age'), P.age, meta.level, [['x_age', r => r.age, 10]]));
        if (P.gender) sheets.push(breakdownSheet(t('sh_gender'), P.gender, meta.level, [['x_gender', r => genderLbl(r.gender), 12]]));
        if (P.copy) sheets.push(copySheet(P.copy));
      }
      const r = meta ? { since: meta.since, until: meta.until } : (D0 && D0.date.since ? { since: D0.date.since, until: D0.date.until } : apiRange());
      const fname = `stats-meta-kpis${actId() ? '_act-' + actId() : ''}_${r.since}_${r.until}.xlsx`;
      window.__smkXlsx.download(sheets, fname);
      const warn = meta && Array.isArray(meta.warnings) && meta.warnings.length ? ' — ' + t('ex_copy_warn', { m: meta.warnings.map(w => String(w).replace(/^copy: /, '')).join(' · ') }) : '';
      EX.msg = t('ex_done', { n: sheets.length }) + warn;
    } catch (e) {
      EX.err = (e && e.message) || t('err_generic'); EX.msg = '';
    }
    EX.busy = false;
    if (state.modal === 'export') renderModal();
  }

  // ---------------------------------------------------------- proposal (best KPIs → editable campaign → created PAUSED)
  function segments(rows, keyFn, labelFn) {
    const m = new Map();
    for (const r of rows || []) {
      const k = keyFn(r);
      if (k == null) continue;
      const s = m.get(k) || { key: k, label: labelFn(r), spend: 0, purchases: 0, value: 0, hasValue: false, impressions: 0, link: 0 };
      s.spend += r.spend || 0; s.purchases += r.purchases || 0; s.impressions += r.impressions || 0; s.link += r.link_clicks || 0;
      if (r.value != null) { s.value += r.value; s.hasValue = true; }
      m.set(k, s);
    }
    return [...m.values()].map(s => Object.assign(s, {
      cpa: s.purchases > 0 ? s.spend / s.purchases : null,
      roas: s.hasValue && s.spend > 0 ? s.value / s.spend : null,
      ctr: s.impressions > 0 ? (s.link / s.impressions) * 100 : null,
    })).sort((a, b) => b.spend - a.spend);
  }
  /** Kept / dropped segments: kept = CPA on target (with tolerance) or ROAS at least orange; dropped = spends without selling. */
  function judge(segs) {
    const tgt = S.targetCpa, tol = 1 + S.cpaTolerancePct / 100;
    const anyPurch = segs.some(s => s.purchases > 0);
    segs.forEach(s => {
      if (s.spend < S.minSpend && s.impressions < S.minImpressions) { s.st = 'u'; return; }
      if (anyPurch) {
        const good = s.purchases > 0 && ((s.cpa != null && s.cpa <= tgt * tol) || (s.roas != null && s.roas >= S.t.roas.warn));
        const bad = (s.purchases === 0 && s.spend >= S.killMultiplier * tgt) || (s.cpa != null && s.cpa > tgt * tol && !(s.roas != null && s.roas >= S.t.roas.warn));
        s.st = good ? 'k' : bad ? 'd' : 'u';
      } else {
        s.st = s.impressions >= S.minImpressions ? (band('ctr', s.ctr) === 'red' ? 'd' : 'k') : 'u';
      }
    });
    // Nothing on target: keep the cheapest segment that sells.
    if (anyPurch && !segs.some(s => s.st === 'k')) {
      const best = segs.filter(s => s.purchases > 0).sort((a, b) => a.cpa - b.cpa)[0];
      if (best) best.st = 'k';
    }
    return segs;
  }
  function ageBounds(key) { const m = String(key).match(/^(\d+)(?:-(\d+)|\+)?/); return m ? [Number(m[1]), m[2] ? Number(m[2]) : 65] : null; }
  function analyse(exp, days) {
    const P = exp.parts || {};
    const ads = (P.copy || []).filter(r => r.spend > 0 || r.impressions > 0);
    const selling = ads.filter(r => r.purchases > 0 && r.spend >= S.minSpend);
    let best, basis = 'purchases';
    if (selling.length) {
      const order = { win: 0, opt: 1, lose: 2, ins: 3 };
      best = selling.slice().sort((a, b) => order[verdictOf(metaRow(a)).k] - order[verdictOf(metaRow(b)).k] || (b.roas == null ? -1 : b.roas) - (a.roas == null ? -1 : a.roas) || a.cpa - b.cpa)[0];
    } else {
      basis = 'ctr';
      best = ads.filter(r => r.impressions >= S.minImpressions).sort((a, b) => (b.ctr_link || 0) - (a.ctr_link || 0))[0] || ads[0];
    }
    // Ages
    const ages = judge(segments(P.age, r => r.age, r => r.age).filter(s => s.key !== 'Unknown').sort((a, b) => (ageBounds(a.key) || [99])[0] - (ageBounds(b.key) || [99])[0]));
    const keptA = ages.filter(s => s.st === 'k').map(s => ageBounds(s.key)).filter(Boolean);
    let ageMin = 18, ageMax = 65, ageAll = true;
    if (keptA.length && ages.some(s => s.st === 'd')) {
      ageMin = Math.max(18, Math.min(...keptA.map(b => b[0]))); ageMax = Math.min(65, Math.max(...keptA.map(b => b[1])));
      ageAll = ageMin === 18 && ageMax === 65;
    }
    // Genders
    const gens = judge(segments(P.gender, r => r.gender, r => genderLbl(r.gender)).filter(s => s.key === 'female' || s.key === 'male'));
    let genders = [];
    const gk = gens.filter(s => s.st === 'k'), gd = gens.filter(s => s.st === 'd');
    if (gk.length === 1 && gd.length === 1) genders = [gk[0].key === 'male' ? 1 : 2];
    // Placements
    const places = judge(segments(P.placement, r => (POS_MAP[r.publisher_platform] && POS_MAP[r.publisher_platform][r.platform_position]) ? r.publisher_platform + ':' + POS_MAP[r.publisher_platform][r.platform_position] : null,
      r => (PLATFORM_NAME[r.publisher_platform] || r.publisher_platform) + ' · ' + posLabel(r.publisher_platform, r.platform_position)));
    const dropped = places.filter(s => s.st === 'd');
    const manual = dropped.length > 0 && places.some(s => s.st === 'k');
    const sel = {};
    // Manual set = placements that delivered and were not losing (never-delivered placements are left out).
    if (manual) places.forEach(s => { if (s.st !== 'd') sel[s.key] = true; });
    const budget = Math.max(2 * S.targetCpa, best ? (best.spend / Math.max(1, days)) * 1.2 : 0, 1);
    return { ads, best, basis, ages, ageMin, ageMax, ageAll, gens, genders, places, manual, dropped, sel, budget: Math.ceil(budget) };
  }
  async function startProposal() {
    PR.err = ''; PR.result = null; PR.an = null; PR.f = null;
    if (metaState() !== 'ok') { PR.step = 'meta'; renderModal(); return; }
    if (!actId()) { PR.step = 'error'; PR.err = t('ex_no_act'); renderModal(); return; }
    if (!PR.since) { const r = apiRange(); PR.since = r.since; PR.until = r.until; }
    if (PR.since > PR.until) { PR.step = 'error'; PR.err = t('bad_range'); renderModal(); return; }
    PR.step = 'loading'; PR.msg = t('pr_loading', { s: PR.since, u: PR.until }); renderModal();
    const exp = await api('export', { act: actId(), since: PR.since, until: PR.until, level: 'account', parts: ['age', 'gender', 'placement', 'copy'] }, 240000);
    if (!exp.ok) { PR.step = 'error'; PR.err = apiErr(exp); renderModal(); return; }
    const days = Math.round((parseYmd(PR.until) - parseYmd(PR.since)) / 86400000) + 1;
    const an = analyse(exp, days);
    if (!an.best) { PR.step = 'error'; PR.err = t('pr_no_ads'); renderModal(); return; }
    PR.msg = t('pr_loading_src'); renderModal();
    const src = await api('source', { act: actId(), ad_id: an.best.ad_id }, 60000);
    if (!src.ok) { PR.step = 'error'; PR.err = apiErr(src); renderModal(); return; }
    PR.exp = exp; PR.src = src; PR.an = an;
    const cr = src.creative || {};
    const today = ymd(new Date());
    const gTag = an.genders.length ? ' · ' + genderLbl(an.genders[0] === 1 ? 'male' : 'female') : '';
    PR.f = {
      name: `SMK · ${an.best.ad || src.ad.name} · ${an.ageMin}-${an.ageMax}${gTag} · ${today}`.slice(0, 200),
      budget: an.budget, ageMin: an.ageMin, ageMax: an.ageMax, gender: an.genders.length ? String(an.genders[0]) : 'all', adv: false,
      placeAuto: !an.manual, sel: Object.assign({}, an.sel),
      adName: `SMK · ${an.best.ad || src.ad.name}`.slice(0, 200), body: cr.body || '', title: cr.title || '', desc: cr.description || '', link: cr.link || '', cta: cr.cta || 'SHOP_NOW',
    };
    // Automatic placements suggested: pre-tick the placements that delivered, in case the user switches to manual.
    if (!an.manual) Object.keys(POSITIONS).forEach(p => POSITIONS[p].forEach(pos => { if (an.places.some(s => s.key === p + ':' + pos)) PR.f.sel[p + ':' + pos] = true; }));
    PR.step = 'form';
    renderModal();
  }
  const segTable = (segs, fmtKey) => {
    if (!segs.length) return `<p>—</p>`;
    const lbl = { k: t('pr_kept'), d: t('pr_dropped'), u: t('pr_little') };
    return `<table><thead><tr><th class="l">${esc(t('pr_seg'))}</th><th>${esc(t('th_spend'))}</th><th>${esc(t('th_purch'))}</th><th>CPA</th><th>ROAS</th><th></th></tr></thead><tbody>${segs.slice(0, 12).map(s => `<tr class="${s.st === 'k' ? 'k' : s.st === 'd' ? 'd' : ''}"><td class="l">${esc(fmtKey ? fmtKey(s) : s.label)}</td><td>${money(s.spend)}</td><td>${int(s.purchases)}</td><td>${s.cpa == null ? '—' : money(s.cpa)}</td><td>${s.roas == null ? '—' : x2(s.roas)}</td><td><span class="st ${s.st}">${esc(lbl[s.st])}</span></td></tr>`).join('')}</tbody></table>`;
  };
  function proposeHtml() {
    const head = `<h2>${esc(t('pr_title'))}</h2>`;
    const period = `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12px;color:#475467;margin:0 0 12px">${esc(t('pr_period'))} : <input type="date" data-prp="since" value="${esc(PR.since)}" style="font:inherit;border:1px solid #D0D5DD;border-radius:6px;padding:3px 6px"> → <input type="date" data-prp="until" value="${esc(PR.until)}" style="font:inherit;border:1px solid #D0D5DD;border-radius:6px;padding:3px 6px"> <button class="lnk" data-act="prRerun">${esc(t('pr_rerun'))}</button></div>`;
    const close = `<button class="b2" data-act="modalClose">${esc(t('cancel'))}</button>`;
    if (PR.step === 'meta') {
      const ms = metaState();
      return `<div class="sp">${head}<div class="ban ${ms === 'unconf' ? 'warn' : 'info'}"><span>${esc(ms === 'unconf' ? t('ex_meta_unconf') : t('pr_meta_off'))}</span>${ms === 'off' ? `<button data-act="metaConnect">${esc(t('connect_meta'))}</button>` : ''}</div><div class="acts"><span></span><div class="r">${close}</div></div></div>`;
    }
    if (PR.step === 'loading' || PR.step === 'idle') return `<div class="sp">${head}<div style="padding:30px;text-align:center"><div class="spin" style="margin:0 auto 12px"></div>${esc(PR.msg)}</div></div>`;
    if (PR.step === 'error') return `<div class="sp">${head}${period}<div class="ban err"><span>${esc(PR.err)}</span></div><div class="acts"><span></span><div class="r">${close}<button class="b2 pri" data-act="prRerun">${esc(t('try_again'))}</button></div></div></div>`;
    if (PR.step === 'done') {
      const p = PR.result.proposal;
      const active = p.status === 'active';
      return `<div class="sp">${head}<div class="done"><div class="ic">✓</div><h3>${esc(active ? t('pr_published') : t('pr_done_t'))}</h3><p>${esc(t('pr_done_p', { n: p.name }))}${PR.result.texts_changed === false ? '<br><small>' + esc(t('pr_texts_kept')) + '</small>' : ''}</p>
        ${PR.err ? `<div class="ban err"><span>${esc(PR.err)}</span></div>` : ''}
        <div class="acts"><a class="b2" href="${esc(p.url)}" target="_blank" rel="noopener">${esc(t('pr_open'))}</a>
        ${active ? `<button class="b2" data-act="prPause" ${PR.busy ? 'disabled' : ''}>${esc(t('pr_pause'))}</button>` : `<button class="b2 acc" data-act="prPublish" ${PR.busy ? 'disabled' : ''}>${esc(t('pr_publish'))}</button>`}
        <button class="b2" data-act="profile">${esc(t('pr_profile'))}</button><button class="b2" data-act="prNew">${esc(t('pr_new'))}</button></div></div>
        <div class="acts"><span></span><div class="r">${close.replace(esc(t('cancel')), '✕')}</div></div></div>`;
    }
    // form
    const an = PR.an, src = PR.src, f = PR.f, cr = src.creative || {}, tg = (src.adset && src.adset.targeting) || {};
    const cur = (src.account && src.account.currency) || CUR.sym;
    const ageOpts = sel => { let o = ''; for (let a = 18; a <= 65; a++) o += `<option value="${a}" ${+sel === a ? 'selected' : ''}>${a === 65 ? '65+' : a}</option>`; return o; };
    const segBy = new Map(an.places.map(s => [s.key, s]));
    const posHtml = Object.keys(POSITIONS).map(plat => `<div class="pg"><b>${esc(PLATFORM_NAME[plat])}</b>${POSITIONS[plat].map(pos => {
      const k = plat + ':' + pos, s = segBy.get(k);
      const info = s ? (s.cpa != null ? ' · CPA ' + money(s.cpa) : s.spend ? ' · ' + money(s.spend) : '') + (s.roas != null ? ' · ROAS ' + x2(s.roas) : '') : '';
      return `<label class="chk ${f.placeAuto ? 'dis' : ''}"><input type="checkbox" data-pos="${k}" ${f.sel[k] ? 'checked' : ''} ${f.placeAuto ? 'disabled' : ''}> <span>${esc(t('pos_' + plat + '_' + pos))}<small style="${s && s.st === 'd' ? 'color:#B42318' : s && s.st === 'k' ? 'color:#067647' : ''}">${esc(info)}</small></span></label>`;
    }).join('')}</div>`).join('');
    const ctas = CTAS.includes(f.cta) ? CTAS : [f.cta].concat(CTAS);
    const editable = !!cr.editable;
    const keptAges = an.ages.filter(s => s.st === 'k').map(s => s.key).join(', ');
    const geoTxt = [(tg.countries || []).join(', '), tg.regions ? t('pr_regions', { n: tg.regions }) : '', tg.cities ? t('pr_cities', { n: tg.cities }) : '', (tg.interests || []).slice(0, 5).join(', '), tg.custom_audiences ? t('pr_auds', { n: tg.custom_audiences }) : ''].filter(Boolean).join(' · ');
    const b = an.best;
    const why = `<div class="why">
      <h4>${esc(t('pr_best_ad'))}</h4><p><b>${esc(b.ad)}</b><br>${esc(b.campaign)}</p>
      <p>${esc(t('th_spend'))} ${money(b.spend)} · ${esc(t('th_purch'))} ${int(b.purchases)} · CPA ${b.cpa == null ? '—' : money(b.cpa)} · ROAS ${b.roas == null ? '—' : x2(b.roas)} · CTR ${b.ctr_link == null ? '—' : pct(b.ctr_link)}</p>
      ${an.basis === 'ctr' ? `<p style="color:#B54708">${esc(t('pr_basis_ctr'))}</p>` : ''}
      <p style="color:#667085">${esc(t('pr_ads_n', { n: an.ads.length }))}</p>
      <h4>${esc(t('ex_age'))}</h4><p>${esc(an.ageAll ? t('pr_age_all') : t('pr_age_why', { a: keptAges }))}</p>${segTable(an.ages)}
      <h4>${esc(t('ex_gender'))}</h4><p>${esc(an.genders.length ? t('pr_gender_one', { g: genderLbl(an.genders[0] === 1 ? 'male' : 'female') }) : t('pr_gender_all'))}</p>${segTable(an.gens)}
      <h4>${esc(t('ex_placement'))}</h4><p>${esc(an.manual ? t('pr_place_manual_why', { l: an.dropped.map(s => s.label).join(', ') }) : t('pr_place_auto_why'))}</p>${segTable(an.places)}
      <h4>${esc(t('pr_src_adset'))}</h4><p>${esc(src.adset.name)}${src.adset.optimization_goal ? ' · ' + esc(src.adset.optimization_goal) : ''}</p>
    </div>`;
    return `<div class="sp wide">${head}${period}
      <div class="prg"><div>
        <div class="box"><h4>${esc(t('pr_sec_campaign'))}</h4>
          <div class="f"><label>${esc(t('pr_name'))}</label><input data-pf="name" value="${esc(f.name)}" maxlength="250"></div>
          <div class="row2">
            <div class="f"><label>${esc(t('pr_budget', { c: cur }))}</label><input type="number" min="1" step="any" data-pf="budget" value="${esc(f.budget)}"><small>${esc(t('pr_budget_h', { v: money(an.budget) }))}</small></div>
            <div class="f"><label>${esc(t('pr_objective'))}</label><input value="${esc(STR[UI]['obj_' + src.campaign.objective] ? t('obj_' + src.campaign.objective) : src.campaign.objective)}" disabled></div>
          </div></div>
        <div class="box"><h4>${esc(t('pr_sec_audience'))}</h4>
          <div class="f"><label>${esc(t('pr_countries'))}</label><input value="${esc(geoTxt || '—')}" disabled><small>${esc(t('pr_countries_h', { n: src.adset.name }))}</small></div>
          <div class="row2">
            <div class="f"><span class="lb">${esc(t('pr_age'))}</span><div style="display:flex;gap:6px;align-items:center"><select data-pf="ageMin">${ageOpts(f.ageMin)}</select> ${esc(t('pr_to'))} <select data-pf="ageMax">${ageOpts(f.ageMax)}</select></div></div>
            <div class="f"><span class="lb">${esc(t('pr_gender'))}</span><div class="radio">${[['all', 'g_all'], ['2', 'g_female'], ['1', 'g_male']].map(([v, l]) => `<label><input type="radio" name="smkg" data-pf="gender" value="${v}" ${f.gender === v ? 'checked' : ''}> ${esc(t(l))}</label>`).join('')}</div></div>
          </div>
          <label class="chk"><input type="checkbox" data-pf="adv" ${f.adv ? 'checked' : ''}> <span>${esc(t('pr_adv'))}</span></label>
        </div>
        <div class="box"><h4>${esc(t('pr_sec_place'))}</h4>
          <div class="radio"><label><input type="radio" name="smkp" data-pf="placeAuto" value="1" ${f.placeAuto ? 'checked' : ''}> ${esc(t('pr_place_auto'))}</label><label><input type="radio" name="smkp" data-pf="placeAuto" value="0" ${f.placeAuto ? '' : 'checked'}> ${esc(t('pr_place_manual'))}</label></div>
          <div class="pos">${posHtml}</div></div>
        <div class="box"><h4>${esc(t('pr_sec_ad'))} <small style="font-weight:500;color:#667085">${esc(t('pr_visual'))}</small></h4>
          ${editable ? '' : `<div class="ban warn"><span>${esc(t('pr_not_editable', { f: t('fm_' + (cr.mode || 'post')) }))}</span></div>`}
          <div class="f"><label>${esc(t('pr_ad_name'))}</label><input data-pf="adName" value="${esc(f.adName)}" maxlength="250"></div>
          <div class="f"><label>${esc(t('pr_body'))}</label><textarea data-pf="body" ${editable ? '' : 'disabled'}>${esc(f.body)}</textarea></div>
          <div class="row2"><div class="f"><label>${esc(t('pr_headline'))}</label><input data-pf="title" value="${esc(f.title)}" ${editable ? '' : 'disabled'}></div><div class="f"><label>${esc(t('pr_desc'))}</label><input data-pf="desc" value="${esc(f.desc)}" ${editable ? '' : 'disabled'}></div></div>
          <div class="row2"><div class="f"><label>${esc(t('pr_link'))}</label><input type="url" data-pf="link" value="${esc(f.link)}" ${editable ? '' : 'disabled'}></div>
            <div class="f"><label>${esc(t('pr_cta'))}</label><select data-pf="cta" ${editable ? '' : 'disabled'}>${ctas.map(c => `<option value="${esc(c)}" ${f.cta === c ? 'selected' : ''}>${esc(STR[UI]['cta_' + c] ? t('cta_' + c) : c)}</option>`).join('')}</select></div></div>
        </div>
      </div>
      <div><h4 style="margin:0 0 8px;font-size:13px">${esc(t('pr_why'))}</h4>${why}</div></div>
      ${PR.err ? `<div class="ban err" style="margin-top:10px"><span>${esc(PR.err)}</span></div>` : ''}
      <div class="ban info" style="margin-top:10px"><span>${esc(t('pr_safe'))}</span></div>
      <div class="acts"><span></span><div class="r">${close}<button class="b2 pri" data-act="prCreate" ${PR.busy ? 'disabled' : ''}>${esc(PR.busy ? t('pr_creating') : t('pr_create'))}</button></div></div>
    </div>`;
  }
  async function createProposal() {
    const f = PR.f;
    PR.err = '';
    const budget = parseFloat(String(f.budget).replace(',', '.'));
    if (!String(f.name).trim()) PR.err = t('pr_err_name');
    else if (!(budget > 0)) PR.err = t('pr_err_budget');
    else if (+f.ageMin > +f.ageMax) PR.err = t('pr_err_age');
    else if (!f.placeAuto && !Object.keys(f.sel).some(k => f.sel[k])) PR.err = t('pr_err_place');
    else if (PR.src.creative.editable && f.link && !/^https?:\/\//i.test(f.link)) PR.err = t('pr_err_link');
    if (PR.err) { renderModal(); return; }
    const placements = { auto: !!f.placeAuto };
    if (!f.placeAuto) Object.keys(POSITIONS).forEach(p => { placements[p + '_positions'] = POSITIONS[p].filter(pos => f.sel[p + ':' + pos]); });
    PR.busy = true; renderModal();
    const d = await api('create', {
      act: actId(), source_ad_id: PR.an.best.ad_id,
      campaign: { name: f.name.trim(), daily_budget: budget },
      adset: { name: f.name.trim(), age_min: +f.ageMin, age_max: +f.ageMax, genders: f.gender === 'all' ? [] : [+f.gender], advantage_audience: !!f.adv, placements },
      ad: { name: f.adName.trim() || f.name.trim(), primary_text: f.body, title: f.title, description: f.desc, link: f.link.trim(), cta: f.cta === 'NO_BUTTON' ? '' : f.cta },
    }, 120000);
    PR.busy = false;
    if (!d.ok) { PR.err = apiErr(d); renderModal(); return; }
    PR.result = d; PR.step = 'done'; PR.err = '';
    renderModal();
  }
  async function setProposalStatus(status) {
    const p = PR.result.proposal;
    if (status === 'active' && !confirm(t('pr_publish_confirm', { n: p.name, b: (p.budget != null ? NF2.format(p.budget) + ' ' + p.currency : '') }))) return;
    PR.busy = true; PR.err = ''; renderModal();
    const d = await api('proposal_status', { id: p.id, status }, 60000);
    PR.busy = false;
    if (!d.ok) PR.err = apiErr(d); else PR.result.proposal = d.proposal;
    renderModal();
  }

  // ------------------------------------------------------------------ render all
  function render() { UI = pickLang(); renderHeader(); renderBody(); renderModal(); updateLauncher(); }
  function updateLauncher() {
    const lc = $('.lc');
    $('.launch').title = t('launch_t');
    if (!AUTH.connected) { lc.textContent = AUTH.checked ? '🔒' : ''; return; }
    const D = state.levels[currentLevel()] || ds();
    if (!D) { lc.textContent = ''; return; }
    const rows = enrich(D);
    const c = { win: 0, opt: 0, lose: 0 };
    rows.forEach(r => { if (c[r.v.k] != null) c[r.v.k]++; });
    lc.textContent = `${c.win} · ${c.opt} · ${c.lose}`;
  }
  function overlay(on, msg) {
    const o = $('.ovl');
    o.classList.toggle('on', !!on);
    if (on) o.innerHTML = `<div class="spin"></div><div>${esc(msg || t('reading'))}</div><div class="bar"><i style="width:${Math.round((state.progress || 0) * 100)}%"></i></div><div style="font-weight:500;font-size:11px;color:#667085">${esc(t('overlay_note'))}</div>`;
  }

  // ------------------------------------------------------------------ actions
  async function scanLevel(level) {
    const cur = currentLevel();
    if (level && level !== cur) {
      state.msg = t('opening_tab', { lv: lvMany(level) }); overlay(true, state.msg);
      await clickTab(level);
      const ok = await waitReady(level);
      if (!ok) throw new Error(t('err_tab_timeout', { lv: lvMany(level) }));
    } else {
      await waitReady(null, 8000);
    }
    const lv = currentLevel();
    if (!lv) throw new Error(t('err_open_table'));
    state.progress = 0;
    state.msg = t('reading_lv', { lv: lvMany(lv) });
    overlay(true, state.msg);
    const col = await collect(p => { state.progress = p; const b = root.querySelector('.ovl .bar i'); if (b) b.style.width = Math.round(p * 100) + '%'; });
    state.levels[lv] = buildDataset(lv, col);
    state.view = lv;
    uploadScan(state.levels[lv]);
  }
  async function run(fn) {
    if (state.scanning || !AUTH.connected) return;
    state.scanning = true; state.error = '';
    try { await fn(); } catch (e) { state.error = (e && e.message) || String(e); }
    state.scanning = false; overlay(false);
    render(); schedulePaint();
  }
  const scan = level => run(() => scanLevel(level || state.view || currentLevel()));
  const fullScan = () => run(async () => {
    const start = currentLevel();
    for (const lv of LEVELS) await scanLevel(lv);
    if (start && start !== currentLevel()) { overlay(true, t('returning')); await clickTab(start); await waitReady(start); }
    state.view = start || 'ad';
  });

  function open() {
    state.open = true;
    $('.drawer').classList.add('open');
    if (!state.view) state.view = currentLevel() || 'adset';
    render();
    refreshAuth(false).then(() => {
      if (!AUTH.connected) return;
      const D = state.levels[state.view];
      if (!D || D.date.raw !== urlDate().raw) scan(state.view);
    });
  }
  function close() { state.open = false; $('.drawer').classList.remove('open'); state.modal = null; renderModal(); schedulePaint(); }

  root.addEventListener('click', e => {
    const a = e.target.closest('[data-act]');
    if (!a) return;
    const act = a.getAttribute('data-act');
    if (act === 'toggle') { state.open ? close() : open(); return; }
    if (act === 'close') return close();
    if (act === 'rescan') return scan(state.view || currentLevel());
    if (act === 'full') return fullScan();
    if (act === 'settings') { state.modal = 'settings'; renderModal(); return; }
    if (act === 'modalClose') return closeModal();
    if (act === 'setReset') { const keepLang = S.lang; S = clone(DEFAULTS); S.lang = keepLang; renderModal(); return; }
    if (act === 'setSave') { readSettingsForm(); saveSettings(); state.modal = null; rebuild(); render(); schedulePaint(); return; }
    if (act === 'kpicols') return openKpiColumns();
    if (act === 'export') { EX.err = ''; EX.msg = ''; state.modal = 'export'; renderModal(); refreshAuth(true).then(() => { if (state.modal === 'export') renderModal(); }); return; }
    if (act === 'exportGo') return runExport();
    if (act === 'propose') { state.modal = 'propose'; PR.step = 'loading'; PR.msg = t('gate_checking'); renderModal(); refreshAuth(true).then(startProposal); return; }
    if (act === 'prRerun' || act === 'prNew') { PR.step = 'loading'; return startProposal(); }
    if (act === 'prCreate') return createProposal();
    if (act === 'prPublish') return setProposalStatus('active');
    if (act === 'prPause') return setProposalStatus('paused');
    if (act === 'metaConnect') { openSite('lang=' + UI, 'profile'); return; }
    if (act === 'profile') { openSite('lang=' + UI, 'profile'); return; }
    if (act === 'gate:login' || act === 'gate:signup') { openSite('connect=extension&lang=' + UI, act === 'gate:login' ? 'login' : 'signup'); return; }
    if (act === 'customApply') {
      const f = root.getElementById('cf').value, to = root.getElementById('ct').value;
      if (!f || !to || f > to) { alert(t('bad_range')); return; }
      return navigateDate(parseYmd(f), parseYmd(to), '');
    }
    if (act === 'customCancel') { state.showCustom = false; renderHeader(); renderBody(); return; }
    if (act === 'expAll') { state.expAll = !state.expAll; state.exp.clear(); renderBody(); return; }
    if (act === 'hidezero' || act === 'q' || act === 'date' || act === 'sortsel') return;
    const [k, v] = act.split(':');
    if (k === 'lang') { S.lang = v; saveSettings(); render(); return; }
    if (k === 'level') {
      if (state.levels[v]) { state.view = v; render(); if (v !== currentLevel()) clickTab(v).then(() => schedulePaint()).catch(() => { }); }
      else scan(v);
      return;
    }
    if (k === 'scan') { if (state.modal === 'export') closeModal(); return scan(v); }
    if (k === 'filter') { state.filter = state.filter === v ? 'all' : v; renderBody(); return; }
    if (k === 'stage') { state.stage = state.stage === v ? '' : v; renderBody(); const tw = $('.tw'); if (tw && state.stage) tw.scrollIntoView({ block: 'start', behavior: 'smooth' }); return; }
    if (k === 'exp') { const id = act.slice(4); if (state.exp.has(id)) state.exp.delete(id); else state.exp.add(id); renderTable(); return; }
    if (k === 'sort') {
      if (state.sort.k === v) state.sort.dir *= -1;
      else state.sort = { k: v, dir: (v === 'cpa' || v === 'name' || v === 'verdict') ? 1 : -1 };
      renderTable(); syncSortSelect(); return;
    }
  });
  function syncSortSelect() { const s = root.querySelector('[data-act="sortsel"]'); if (s && [...s.options].some(o => o.value === state.sort.k)) s.value = state.sort.k; }
  root.addEventListener('input', e => {
    const a = e.target;
    if (a.getAttribute('data-act') === 'q') { state.q = a.value; renderTable(); return; }
    const pf = a.getAttribute('data-pf');
    if (pf && PR.f && a.type !== 'radio' && a.type !== 'checkbox') PR.f[pf] = a.value;
    const prp = a.getAttribute('data-prp');
    if (prp) PR[prp] = a.value;
    const exl = a.getAttribute('data-exl');
    if (exl) EX[exl] = a.value;
  });
  root.addEventListener('change', e => {
    const a = e.target;
    const act = a.getAttribute('data-act');
    if (act === 'hidezero') { S.hideZeroSpend = a.checked; saveSettings(); renderTable(); return; }
    if (act === 'sortsel') { const v = a.value; state.sort = { k: v, dir: v === 'cpa' ? 1 : -1 }; renderTable(); return; }
    if (act === 'date') {
      const v = a.value;
      if (v === '__custom') { state.showCustom = true; renderBody(); return; }
      const r = presetRange(v);
      if (r) navigateDate(r[0], addDays(r[1], -1), v);
      return;
    }
    const ex = a.getAttribute('data-ex');
    if (ex) { EX.parts[ex] = a.checked; return; }
    const exl = a.getAttribute('data-exl');
    if (exl) { EX[exl] = a.value; return; }
    const pos = a.getAttribute('data-pos');
    if (pos && PR.f) { PR.f.sel[pos] = a.checked; return; }
    const pf = a.getAttribute('data-pf');
    if (pf && PR.f) {
      if (pf === 'adv') PR.f.adv = a.checked;
      else if (pf === 'placeAuto') { PR.f.placeAuto = a.value === '1'; renderModal(); }
      else PR.f[pf] = a.value;
    }
  });
  window.addEventListener('keydown', e => {
    if (e.altKey && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); state.open ? close() : open(); }
    else if (e.key === 'Escape' && state.open) { if (state.modal) closeModal(); else close(); }
  }, true);

  // ------------------------------------------------------------------ colour the native Ads Manager cells (style only, no DOM insertion)
  const NATIVE = [
    { re: /^(website_ctr:link_click|inline_link_click_ctr)$/, m: 'ctr' },
    { re: /^(cost_per_action_type:link_click|cost_per_inline_link_click)$/, m: 'cpc' },
    { re: /^cpm$/, m: 'cpm' },
    { re: /^frequency$/, m: 'freq' },
    { re: /purchase_roas/, m: 'roas' },
    { re: /^cost_per_action_type:(offsite_conversion\.fb_pixel_purchase|omni_purchase|purchase)$/, m: 'cpa' },
    { re: /^cost_per_result$/, m: 'cpa', label: true },
  ];
  const painted = new Map();
  // Cell markers are zero-size wrappers; the visible cell is their first sized descendant.
  function boxOf(el) {
    let c = el;
    for (let i = 0; i < 5 && c; i++) {
      const b = c.getBoundingClientRect();
      if (b.width > 0 && b.height > 0) return b.width < 700 ? c : null;
      c = c.firstElementChild;
    }
    return null;
  }
  function setPaint(el, color, bar) {
    if (!painted.has(el)) painted.set(el, { bs: el.style.boxShadow, bg: el.style.backgroundColor });
    el.style.boxShadow = `inset ${bar ? 4 : 3}px 0 0 ${color}`;
    el.style.backgroundColor = bar ? '' : color + '1F';
  }
  function unpaint(el) { const o = painted.get(el); if (o) { el.style.boxShadow = o.bs; el.style.backgroundColor = o.bg; painted.delete(el); } }
  function unpaintAll() { [...painted.keys()].forEach(unpaint); }
  function paintNative() {
    const tb = tableEl();
    if (!S.colorNative || !tb || !AUTH.connected) { unpaintAll(); return; }
    const D = state.levels[currentLevel()];
    const seen = new Set();
    tb.querySelectorAll('[data-surface*="/table_cell:"]').forEach(el => {
      const m = el.getAttribute('data-surface').match(/table_row:([^/]+)\/table_cell:([^/]+)$/);
      if (!m || m[1].startsWith('-')) return;
      const key = normKey(m[2]);
      const row = D && D.byId[m[1]];
      let color = null, bar = false;
      if (/^forObjectType\(name,/.test(key)) {
        if (row && row.v) { color = COL[VERDICT[row.v.k].c]; bar = true; }
      } else {
        const rule = NATIVE.find(n => n.re.test(key));
        if (!rule) return;
        const c = parseCell(el.innerText || '');
        if (c.kind !== 'num') return;
        if (rule.label && !c.lines.some(l => PURCHASE_RE.test(l))) return;
        let b;
        if (rule.m === 'cpa') b = row ? (convOK(row) ? cpaBand(c.v) : 'gray') : cpaBand(c.v);
        else if (rule.m === 'roas') b = row ? (convOK(row) ? band('roas', c.v) : 'gray') : band('roas', c.v);
        else b = row ? (trafficOK(row) ? band(rule.m, c.v) : 'gray') : band(rule.m, c.v);
        color = COL[b];
      }
      const box = boxOf(el);
      if (!box) return;
      seen.add(box);
      if (color) setPaint(box, color, bar); else unpaint(box);
    });
    [...painted.keys()].forEach(el => { if (!seen.has(el) || !el.isConnected) unpaint(el); });
  }
  let paintQueued = false, observed = null;
  const mo = new MutationObserver(() => schedulePaint());
  function schedulePaint() {
    if (paintQueued) return;
    paintQueued = true;
    requestAnimationFrame(() => { paintQueued = false; try { if (tableEl()) { enrichVisible(); paintNative(); } } catch (e) { } });
  }
  function enrichVisible() { const D = state.levels[currentLevel()]; if (D && D.rows.length && !D.rows[0].v) enrich(D); }
  setInterval(() => {
    const tb = tableEl();
    if (tb !== observed) {
      mo.disconnect();
      observed = tb;
      if (tb) mo.observe(tb, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['data-surface'] });
      schedulePaint();
    }
    host.style.display = /\/adsmanager\//.test(location.pathname) ? '' : 'none';
  }, 1500);

  // ------------------------------------------------------------------ boot
  window.__smk = { open, close, scan, fullScan, version: VERSION, settings: () => clone(S), data: () => state.levels };
  updateLauncher();
  (async () => {
    // Light check at page load (no server call): is this browser connected to an account?
    await sleep(300);
    const st = await bridge('auth', {}, 5000);
    if (st.error === 'bridge') AUTH.broken = true;
    else { AUTH.connected = !!st.connected; AUTH.user = st.user || null; AUTH.site = st.site || SITE_DEFAULT; }
    AUTH.checked = true;
    updateLauncher();
    let reopen = null;
    try { reopen = JSON.parse(sessionStorage.getItem(SS_REOPEN) || 'null'); sessionStorage.removeItem(SS_REOPEN); } catch (e) { }
    if (reopen && Date.now() - (reopen.t || 0) < 120000) {
      await waitReady(null, 30000);
      state.view = currentLevel() || reopen.level;
      open();
    }
  })();
})();
