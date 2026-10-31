# Stats Meta KPIs — extension Chrome (v2.1.0)

*English below.*

Tableau de bord KPI dans Meta Ads Manager, relié à un compte gratuit sur
[dehini.com/metakpis](https://dehini.com/metakpis/). Français et anglais.

## Installer (1 minute)

1. Décompresser `stats-meta-kpis-2.1.0.zip` dans un dossier que vous gardez.
2. Ouvrir `chrome://extensions` et activer le **Mode développeur** (en haut à droite).
3. Cliquer sur **Charger l'extension non empaquetée** et choisir le dossier `stats-meta-kpis`.
4. Créer un compte sur [dehini.com/metakpis](https://dehini.com/metakpis/) (Google ou email), puis dans le profil
   cliquer sur **Connecter l'extension**.
5. Ouvrir Meta Ads Manager : bouton **● ● ● KPI** en bas à gauche, ou **Alt + K**.

Pour la retirer : `chrome://extensions` → Stats Meta KPIs → **Supprimer**. Les réglages (seuils) d'une version
précédente (« Rotinia – Meta Ads KPI Dashboard ») sont repris automatiquement.

## Ce qu'elle fait

- **Tableau de bord** (sans connexion Meta) : lit le tableau Ads Manager affiché, colore CPA, ROAS, CTR, CPC, CPM,
  fréquence, hook / hold rate (vert / orange / rouge selon vos seuils) et répond aux 5 questions : campagnes qui
  performent, ensembles à optimiser, publicités qui perdent de l'argent, publicités à analyser, budget gaspillé.
- **Que faire ?** (v2.1) : pour chaque campagne, ensemble et publicité, un diagnostic étape par étape du tunnel
  (diffusion, accroche, rétention, clic, page de destination, conversion, rentabilité) et les actions à mener :
  refaire l'accroche, clarifier l'offre, accélérer la page, revoir le ciblage, augmenter le budget ou couper (avec la
  cause probable). « Plan d'action » regroupe les lignes par étape à traiter ; les commentaires sont aussi dans l'export Excel.
- **Export Excel** (`⬇ Excel`) : un fichier `.xlsx` avec un résumé, les campagnes / ensembles / publicités analysés et,
  avec la connexion Meta Ads, les statistiques par **placement**, **âge** et **genre** (au niveau compte, campagne,
  ensemble ou publicité) et les **textes des annonces** (titre, texte principal, description, lien, bouton) avec leurs KPI.
- **Campagne proposée** (`✨ Campagne`, connexion Meta Ads requise) : l'extension repère la meilleure annonce, les âges,
  le genre et les placements les plus rentables sur la période, et prépare une campagne (budget, audience, placements,
  textes). Tout est modifiable ; chaque choix est expliqué. « Créer sur Meta » la crée **en pause** (campagne +
  ensemble + publicité, mêmes visuels, même pixel et mêmes centres d'intérêt que l'ensemble source). Elle ne dépense
  rien tant que vous ne cliquez pas sur **Publier** (dans l'extension, le profil ou Ads Manager).

## Données

- Un compte connecté est nécessaire. Le jeton du compte reste dans l'extension (jamais dans la page Ads Manager).
- Après chaque analyse, si le partage est activé dans le profil, un résumé des KPI est envoyé à votre compte : il
  alimente « Mes statistiques » et sert, de façon agrégée, à améliorer l'extension. Désactivable à tout moment.
- L'accès Meta (Facebook Login officiel) est chiffré sur le serveur et peut être retiré depuis le profil.
- Le tableau de bord ne clique jamais sur modifier / publier / budget / statut ; il fait défiler le tableau, change
  d'onglet Campagnes / Ensembles / Publicités, et recharge la page seulement quand vous choisissez une période ou
  l'ajout des colonnes KPI.

---

# Stats Meta KPIs — Chrome extension (v2.1.0)

KPI dashboard inside Meta Ads Manager, linked to a free account on
[dehini.com/metakpis](https://dehini.com/metakpis/?lang=en). French and English.

## Install (about 1 minute)

1. Unzip `stats-meta-kpis-2.1.0.zip` into a folder you will keep.
2. Open `chrome://extensions` and switch on **Developer mode** (top right).
3. Click **Load unpacked** and select the `stats-meta-kpis` folder.
4. Create an account on [dehini.com/metakpis](https://dehini.com/metakpis/?lang=en) (Google or email), then click
   **Connect the extension** in your profile.
5. Open Meta Ads Manager: **● ● ● KPI** button at the bottom-left, or **Alt + K**.

## What it does

- **Dashboard** (no Meta connection needed): reads the Ads Manager table on screen, colours CPA, ROAS, CTR, CPC, CPM,
  frequency, hook / hold rate against your thresholds and answers the 5 questions.
- **What to do** (v2.1): for every campaign, ad set and ad, a step-by-step funnel diagnosis (delivery, hook, retention,
  click, landing page, conversion, profitability) with the actions to take — new hook, clearer offer, faster page,
  targeting, scale or cut (with the probable cause). Also in the Excel export.
- **Excel export**: summary, scanned campaigns / ad sets / ads and, with the Meta Ads connection, stats by
  **placement**, **age** and **gender** plus **ad copy** (headline, primary text, description, link, call to action).
- **Suggested campaign** (Meta Ads connection required): built from your best ad, ages, gender and placements; fully
  editable, created **paused** on Meta, published only when you click **Publish**.

## Data

An account is required. If sharing is on (profile → Data & privacy), a KPI summary of each scan is sent to your
account to power “My stats” and, in aggregate, improve the extension. You can turn it off or delete it anytime.
