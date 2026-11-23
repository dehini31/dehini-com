# Tests de Studioma

Faux hébergement local qui reproduit cPanel : `public_html/studioma/` (copie du code) et `studioma-data/` créé à
côté, comme sur le serveur. Les emails sont écrits dans `mail.jsonl` au lieu d'être envoyés (`STM_MAIL_LOG`). Le
routeur `router.php` applique les mêmes interdictions et les mêmes en-têtes que `studioma/.htaccess`, dont la
politique de sécurité (CSP).

```bash
tests/studioma/run.sh /tmp/stm-test
```

`run.sh` prépare un hébergement neuf, lance le serveur PHP (port 8097) et exécute seize batteries, chacune sur une
base vide. Les événements Meta (API Conversions) sont écrits dans `meta.jsonl` (faux ensemble de données) ; dans le
navigateur, un faux script du pixel note les appels à `fbq` :

**`api.js` — l'API seule** (Node, sans navigateur) :

- comptes : inscription (particulier, entreprise), connexion, écritures refusées sans jeton CSRF ni en-tête ;
- modèle par défaut : 3 niveaux, leurs colonnes, colonnes principales et finales, relais automatique ;
- cartes : champs, texte mis en forme nettoyé, check-list, commentaires, pièces jointes (contenu vérifié) ;
- **passage de relais** automatique, manuel et désactivé : même identifiant de carte avant et après, aucune
  copie, commentaires, fichiers, check-list, métadonnées et historique conservés ;
- niveaux et colonnes : créer, renommer, réordonner, dupliquer, archiver, déplacer une colonne d'un niveau à
  l'autre avec ses cartes, suppression refusée tant qu'il reste des cartes sans destination ;
- **droits vérifiés par le serveur** : Studio, carte et pièce jointe introuvables pour un compte non invité ;
  collaborateur limité à ses cartes ; cliente en lecture, commentaire et validation ; un membre ne s'élève pas
  lui-même ; le dernier propriétaire ne peut pas partir ;
- invitations par email et par lien (nominative refusée à un autre compte, révoquée, déjà utilisée) ;
- **invitations et adresse confirmée** : un compte à l'adresse non confirmée ne voit pas l'invitation envoyée à
  son adresse (ni comptée, ni listée avec son lien, ni notifiée, ni rattachée à l'envoi) et ne peut ni la refuser
  ni l'accepter par son adresse, même avec le lien d'une invitation adressée à un autre compte ; lien de
  confirmation (connexion exigée, refusé depuis un autre compte, rouvert sans effet) ; une fois l'adresse
  confirmée, l'invitation apparaît, se refuse ou s'accepte, et les suivantes sont rattachées au compte ;
- validations (demande, modifications demandées, approbation → la carte avance et passe au niveau suivant) ;
- archivage, restauration, suppression groupée, synchronisation par numéro de version, tableau de bord ;
- réseau : profil public sans email, publications, j'aime, partage, relations, recherche ;
- portfolio : étude de cas déclarée, brouillon tiré d'un Studio (privé, image privée), accord de la cliente exigé,
  preuves de travail distinguées, recommandation, activité affichée seulement sur choix et sans nom de projet ;
- entreprises, opportunités, candidatures privées, annuaire des talents, invitation à collaborer, messages ;
- **Continue with Google** (`google.js` crée une clé de test et signe les jetons comme Google) : jetons
  illisibles, falsifiés, expirés, destinés à une autre application, d'un autre émetteur ou à l'adresse non
  confirmée refusés ; création du compte, reconnexion sans doublon, mot de passe à choisir, retrait de Google
  seulement avec un mot de passe ; compte créé par un tiers avec l'adresse de quelqu'un d'autre : mot de passe du
  tiers retiré et sa session fermée ; liaison depuis les réglages ; changement d'adresse : la nouvelle n'est
  confirmée ni par un lien de mot de passe oublié envoyé à l'ancienne, ni par une connexion Google dont l'adresse
  diffère ; entreprise créée avec Google ; suppression d'un compte sans mot de passe ;
- mot de passe oublié (anciennes sessions fermées), suppression d'un projet, fichiers internes interdits.

**`share.js` — le partage avec des invités sans compte** (API seule, comme un navigateur malveillant) :

- liens public et privé (un manager seulement), jeton de 48 caractères ;
- lien public : lecture seule sans compte ; niveaux non partagés, autres Studios et leurs cartes introuvables ;
  pièces jointes servies seulement pour les cartes partagées ; écritures refusées sans adresse vérifiée ; toute
  action hors de la liste fermée refusée (espace de travail, membres, réglages, archivage, suppression,
  invitations, structure, validations demandées…) ; jeton inventé ou tronqué, en-tête manquant ;
- vérification par code : 5 codes faux et le code ne vaut plus, code à usage unique, session d'invité ; demande
  d'édition enregistrée, email au responsable (adresse vérifiée, message, liens Examiner / Approuver / Refuser),
  notification au nom de l'invité ;
- réponse du responsable (autre compte refusé, déjà traitée), périmètre d'édition (une colonne, une carte) :
  cartes et colonnes modifiables exactement celles du périmètre ; responsable et collaborateurs d'une carte
  intouchables ; création, déplacement, check-list et commentaires dans le périmètre seulement ; commentaires des
  autres protégés ;
- emails des modifications : le premier tout de suite (invité, quoi, quand, lien vers la carte), les suivants
  regroupés après la fenêtre de 10 minutes, rien d'envoyé deux fois ;
- lien privé : rien sans vérification, adresse hors liste sans email ni indice, ajout et invitation par email,
  lien magique (faux, d'un autre lien, à usage unique), Commenter, cookie d'un autre lien refusé ;
- validation demandée à un invité (email avec le lien de la carte), refusée pour un invité qui ne voit pas la
  carte ou pas vérifié ; seul l'invité désigné décide ;
- retrait (session fermée, plus de code envoyé), remise, changement de rôle immédiat, suppression (commentaires
  gardant l'adresse) ; lien coupé, expiré, régénéré (l'ancien ne marche plus, l'invité vérifié garde ses
  droits), Studio archivé ; membre connecté renvoyé vers son Studio ; déconnexion de l'invité ; journal.

**`meta.js` — le suivi Meta (pixel + API Conversions)** : chaque action produit le bon événement (inscription,
connexion, projet, Studio, carte, relais, publication, commentaire, invitation, partage, invité, publication, j'aime,
abonnement, mise en relation, consultation d'un profil, d'une entreprise, d'une opportunité, étude de cas,
entreprise, opportunité, candidature, message, talent enregistré, recherches, assistant IA), et rien pour les
consultations répétées (tableau) ou les actions refusées ; même event_id renvoyé au navigateur ; email, id, prénom et
nom hachés (SHA-256), navigateur, IP et `_fbp` ; invités et visiteurs sans email ; jamais de nom de projet, de Studio,
d'entreprise, de titre, de commentaire, de message, de texte de recherche, de clé de lien ou d'invitation, ni de
partie d'adresse après `#`.

**`mcp.js` — le serveur MCP et son OAuth**, comme un assistant IA (claude.ai, ChatGPT, Gemini CLI…) :

- découverte : 401 avec `WWW-Authenticate` et `resource_metadata`, métadonnées de la ressource et du serveur
  d'autorisation à toutes les adresses que cherchent les clients (dans `studioma/` et à la racine du domaine) ;
- enregistrement des applications (adresses de retour refusées : http hors machine locale, `javascript:`) ;
- autorisation : application inconnue ou adresse de retour non enregistrée arrêtées sans redirection, PKCE
  « plain » et ressource étrangère refusés, écran d'accord (connexion exigée), refus, code à usage unique même
  après un `code_verifier` faux ;
- protocole : initialize (version négociée), notifications (202), tools/list, ping, erreurs JSON-RPC, lots, GET 405 ;
- les outils sur un vrai Studio : lecture, création de carte en Markdown, colonne donnée par son nom, nom ambigu
  expliqué, déplacement avec passage de relais (même carte), commentaire, check-list, mise à jour, recherche
  et `fetch` (format ChatGPT), my_work, historique, tableau de bord, niveaux, colonnes, relais, projet, Studio,
  archivage ;
- droits : un autre compte ne voit rien ; invité en lecteur, il lit mais ne peut ni créer, ni déplacer, ni changer
  la structure ;
- projets et Studios : lecture, modification partielle, archivage et restauration ; refusés au lecteur ;
- études de cas : brouillon privé, publication (le reste conservé), plateforme hors liste refusée, demande de
  confirmation à une cliente, étude d'une autre personne introuvable, brouillon tiré d'une carte publiée (refusé
  sans lien de publication, puis avec le lien et les chiffres de la carte) ;
- publications : en son nom, vide refusée, fil « découvrir » d'un autre compte, commentaire (autrice prévenue),
  partage ;
- messages : recherche de personnes sans adresse email, envoi, conversation non lue puis lue, réponse reçue dans
  l'application, destinataire inconnu expliqué, pas de message à soi-même ;
- entreprises : création (propriétaire), modification partielle, valeur hors liste refusée avec les choix (pour
  tous les outils), page d'une autre entreprise non modifiable, publication au nom de l'entreprise (admins
  seulement), opportunité publiée puis fermée, candidatures visibles des seuls admins, candidat prévenu, équipe
  (ajout, accès changé avec l'intitulé conservé, un admin ne se nomme pas propriétaire, retrait, dernière
  propriétaire) ; actions vues par d'autres signalées (`openWorldHint`) ;
- jetons : renouvellement (à usage unique, lié à l'application), application déconnectée et jeton personnel
  supprimé refusés aussitôt, client confidentiel (secret exigé), retour vers la machine locale sur un autre port,
  révocation (RFC 7009).

**`billing.js` — formules et abonnements** (`setup.sh` avec `BILLING=1` : limites appliquées, Paddle sandbox pointé sur
le faux Paddle `fake-paddle.php`, servi par `router.php` sous `/fake-paddle/`) : migration d'une base au schéma 8
sans perte ; limites Free (projets, Studios, archivés hors compte, 30 cartes par mois, compteur qui ne recule pas,
nouveau mois, invitations, liens privés, invités en lecture seule, validations, Insights) ; mêmes limites par le
serveur MCP ; paiement (transaction liée au compte, rien d'activé par le retour du navigateur) ; webhooks (mauvaise
signature, sans signature, trop ancien, corps modifié, rejeu, désordre, client inconnu) ; Solo, montée vers Team
(prorata immédiat), places de membres et invitations en attente, invités hors places ; créations simultanées ;
isolement entre comptes ; descente (prorata sur la prochaine facture, membres en trop en lecture seule) ;
résiliation, reprise, fin confirmée par Paddle, dépassements ; paiement en échec, délai de grâce, régularisation ;
espace client ; indicateurs de l'administration (MRR, ARR, sandbox séparé), formule offerte ; navigateur : page des
prix, mensuel / annuel, invitation à changer de formule, paiement avec un faux Paddle.js, attente de la
confirmation, réglages, Insights, mobile, aucune violation de la CSP.

**`quality.js` — opportunités dans le fil, pages publiques, référencement** : droits de publication (non-membre,
Viewer, visiteur), opportunité publiée → accueil « For you » d'un compte qui ne suit pas l'auteure et Discover, rien de
la carte, du Studio ni du projet privés, republication sans doublon, page publique (canonique, Open Graph, fil d'Ariane,
pas de JobPosting, aucun script exécutable, partages), adresse ancienne ou sans titre → 301, liste publique, modification
(fil et adresse à jour), fermeture (retirée du fil, « Closed », non indexée, hors plan du site), réouverture, carte
terminée, modération (ne revient pas), suppression (partout), réalisations, migration v13 rejouée sans doublon, page des
prix (formules de `lib/plans.php`), plan du site, `robots.txt`, titre / description / données structurées / `h1` de
l'accueil, police locale et scripts différés.

**`quality-e2e.js` — sur téléphone (Chromium, iPhone 13 émulé et 320 px)** : en-tête des visiteurs, bouton Create,
nouveau projet dans une fenêtre (dans l'écran, nommée, boutons atteignables), cartes au clavier, panneau de la carte,
éditeur nommé, changement de colonne par le menu « Move », longue fenêtre de publication atteignable depuis son haut,
opportunité publiée, contenu avant les actions du gestionnaire, opportunité dans le fil d'accueil, lien public copié,
partage, page publique, menu latéral ; 14 écrans à 320 et 390 px (pas de défilement horizontal, un `h1`, boutons
nommés) et 6 pages de visiteurs à 320 px ; aucune erreur ni violation de la CSP.

**`legal.js` — pages légales** (`/studioma/terms/`, `/privacy/`, `/refunds/`) : valeurs par défaut choisies par le
propriétaire, 200 indexable, titre, un `h1`, canonique, description, exploitant / contact / date / pays, aucun champ
`{…}` oublié, sommaire dont chaque ancre existe, liens croisés, aucun script en ligne, CSP ; contenu essentiel (âge
minimum, Paddle revendeur, renouvellement et résiliation, droit applicable, garantie, cookies réellement posés par
`lib/*.php`, Meta en SHA-256, droits) ; pages lisibles sans base de données, type inconnu → 404 ; valeurs remplacées
dans `config.php` (et `billing_plans.refund_days`) ; liens depuis les pages publiques, l'accueil, la page des prix, la
page de paiement, l'inscription et la fenêtre « One last step » ; plan du site ; navigateur à 320 px, sommaire, FAQ
des prix, fenêtre des conditions d'un compte qui ne les a pas acceptées ; aucune erreur ni violation de la CSP.

**`journey.js` — le parcours du créateur** : nouveau compte (Explorer, rien d'acquis, premier jalon), premier Studio et
premier projet (une seule fois), cartes vides sans points, carte éligible (description, liste de contrôle), carte
terminée une seule fois même rouverte, limite du jour (événements comptés, points arrêtés), niveau de Studio dégagé
une fois, niveau Organizer et célébrations, projet terminé après vérification (âge, cartes ouvertes, rôle), Studio
partagé (invitation acceptée, invité vérifié), Team Player (compte vérifié exigé), validations de client (pas de
soi-même, pas d'un propriétaire à sa place, pas d'un Manager, adresse confirmée, invité vérifié), réalisation publiée,
détails et preuves, visibilité, page de vérification publique (rien de privé, pas indexée, aucun script), consentement
du client pour son nom, passeport public (fermé par défaut, réalisations choisies, sections masquables, identifiants
distincts), réalisation déclarée, chronologie et filtres, résumé, jalons (rien d'acquis, rien que la formule Free
refuse), règles de l'administration (versions, Proven Creator, réalisation désactivée), retrait et restitution
motivés (« Revoked » sur la page publique), abonnement terminé sans effet, migration rejouée (décisions retrouvées
dans l'historique), huit recalculs simultanés sans doublon.

**`journey-e2e.js` — le parcours dans Chromium** : jalon de l'accueil, menu « My Achievements », passeport, onglets,
en attente, chronologie filtrée, célébration regroupée non bloquante et unique, détails et preuves, partage (lien
copié, LinkedIn / WhatsApp / Facebook / email encodés, image PNG 1200 × 630, 1080 × 1080, 1080 × 1920 avec QR code,
jamais « Verified » sur une déclaration), page publique, réglages, passeport public, réalisation déclarée, projet
marqué terminé, administration (indicateurs, fiche, retrait), mobile et mouvement réduit, aucune erreur ni violation
de la CSP. L'encodeur de QR code (`assets/qr.js`) a en plus été comparé, module par module, à la bibliothèque Python
`qrcode` (versions 1 à 10, huit masques, UTF-8) : identique.

**`promo.js` — l'offre de lancement** (`setup.sh` avec `PROMO=1` : limites appliquées, paiement fermé comme en
production, règles anti-abus réglées par le test ; les autres batteries tournent sans l'offre) : essai de 30 jours
à l'inscription (limites Team, une seule fois, réclamable par un compte existant, jamais par-dessus un accès Team),
fin de l'essai (Free, rien supprimé, API comprise), lien de parrainage (code aléatoire), clics et visiteurs uniques
(robots et membres ignorés), attribution (première valable, signée, 30 jours ; cookie falsifié ou trop vieux
ignoré), vérification par lien de confirmation et par mot de passe oublié, parrainage de soi refusé, 10 amis : 12
mois civils à la suite de l'essai, une seule fois (même avec 12 vérifications simultanées), immédiate si l'essai est
fini, sans réduire un accès offert, fin de l'année offerte, activité suspecte à examiner puis approuvée, indicateurs
de l'administration, aucun appel à Paddle ni paiement, offre coupée.

**`promo-e2e.js` — l'offre dans Chromium** : page des prix (plus de « Coming soon », « Get 30 Days Free » et « No
credit card required » sur Solo et Team, annonce, mensuel / annuel, Most Popular, aucun champ de carte), accueil
(offre, messages de confiance, lien ?ref= compté), inscription sans paiement, bannière (jours restants, date), Refer &
Earn (lien, copie, partages, statistiques, progression 7 / 10 puis 10 / 10, messages, félicitations), essai terminé,
réclamation par un compte existant, Plan & billing, administration, mobile.

**`publish.js` — partager une carte, la publier dans la communauté** (API seule, comme un navigateur malveillant) :

- lien d'une **seule carte** (public, privé restreint à des adresses vérifiées) : l'invité ne voit que cette carte
  (tableau d'une colonne, aucune autre carte du Studio ni d'un autre Studio), ni historique, ni publication, ni
  pièce jointe non cochée (introuvable, même avec l'adresse du fichier, vignette masquée), ni commentaires s'ils
  ne sont pas montrés ; le lien n'apparaît pas dans le partage du Studio ; gestion réservée aux responsables ;
- demande d'édition (email et notification vers la fenêtre de partage de la carte), accord, l'éditeur ne modifie
  que cette carte (ni création, ni déplacement) ; périmètres séparés (une demande ne se traite pas depuis le
  Studio) ; retrait, lien coupé (410), expiré (410), régénéré (l'ancien : 404), journal, pastille « Shared » ;
- **opportunité** : publication refusée à un non-membre, à un Viewer, sur une carte terminée ; validations
  (description, budget, fichier d'une autre carte) ; copie publique de l'image choisie, fichier de la carte privé ;
  republier sans doublon ; vue publique sans adresse de la carte ni contenu privé ; recherche et filtres ;
- **propositions** : compte obligatoire, pas à soi-même, travaux joints seulement les siens et publiés,
  modification, propositions invisibles du public et des autres professionnels, décisions réservées à l'auteur ;
  accepter ne donne aucun accès ; invitation sur la seule carte (lien privé, vérification) ou dans le Studio ;
- fermeture, réouverture, **fermeture automatique** (carte terminée, archivée, date limite), plus de proposition ;
- **réalisation** : carte terminée seulement, couverture image, fil (J'aime, commentaire, partage), profil,
  retrait (introuvable, copie redevenue privée, absente du fil), republication (J'aime gardés), carte supprimée
  (la réalisation reste, sans source ; plus de partage), suppression d'une opportunité avec ses propositions ;
- le partage d'un **Studio entier** fonctionne comme avant.

**`publish-e2e.js` — Share & publish dans Chromium** : menu selon l'étape de la carte, fenêtre de partage d'une carte
(sans onglet Team ni niveaux, *What guests see*), invité sans compte qui ouvre directement la carte et rien d'autre,
publication d'une opportunité (description de la carte jamais reprise d'office, aperçu, confirmation), pastilles
dans la carte et sur le tableau, proposition d'un professionnel et réponse, réalisation avec couverture choisie,
J'aime, Communauté, fil, profil, mobile (iPhone 13, sans défilement horizontal), aucune erreur ni violation de la CSP.

**`admin.js` — l'administration** (`studioma/admin/`, API seule ; `setup.sh` copie aussi `admin/` avec le mot de
passe de test `test-owner-pass`) :

- accès : sans session `/admin`, avec un compte Studioma, un mauvais mot de passe, un cookie inventé ou par un lien
  de partage → refusé ; écriture sans le jeton CSRF de `/admin` ou sans `X-Requested-With` → refusée ;
- statistiques en direct (en ligne, actifs, inscriptions, séries sur 30 jours, fil) **sans nom de projet, de
  Studio, titre de carte ni texte de message** ; recherche, filtres et fiche d'un compte ;
- actions : adresse confirmée, lien de mot de passe, email direct (`{name}`), déconnexion partout, jeton d'assistant
  IA puis déconnexion des assistants ;
- **suspension** : session fermée, connexion refusée (`suspended`), assistants IA refusés, aucun lien de mot de
  passe oublié, profil introuvable, absent de la recherche et de l'annuaire, publications masquées ; levée → tout
  revient ;
- suppression refusée pour la seule propriétaire d'un projet partagé, acceptée sinon (adresse retapée) ; export
  CSV (formule neutralisée, refusé sans session) ;
- modération : commentaire et publication supprimés, offre fermée, page d'entreprise supprimée ;
- journal des emails (code de vérification masqué), email d'essai ; campagnes : publics sans désinscrits ni
  suspendus, aperçu échappé, lien https, essai, envoi par lots, en-têtes de désinscription en un clic, pause,
  reprise, désinscrit sauté à l'envoi, annulation, duplication, suppression ;
- page de désinscription (GET sans effet, POST en un clic, réinscription, signature fausse refusée) ; `cron.php`
  (404 depuis le web, exécution en ligne de commande) ; journal de l'administration ; page sans script en ligne
  ni pixel, CSP appliquée.

**`e2e.js` — l'application dans Chromium** (Playwright), avec trois comptes dans trois navigateurs :

- landing ; inscription ; premiers pas (profil, premier Studio avec le modèle par défaut) ;
- cartes créées à la suite, réordonnées et déplacées au glisser-déposer (ordre conservé après rechargement),
  relais automatique vers Production puis manuel vers Publication ;
- panneau d'une carte : tous les champs, script mis en forme, check-list, pièce jointe, commentaire, historique ;
- colonne renommée d'un clic, ajoutée, réordonnée, supprimée ; niveau ajouté, réordonné, archivé ; vue d'un seul
  niveau ; sélection multiple ; filtres ;
- calendrier (échéance déplacée en glissant la carte), My work, Insights, espace de travail ;
- invitation par lien et par email, inscription depuis l'invitation, **synchronisation entre deux navigateurs**,
  collaborateur qui ne déplace que ses cartes, mention `@`, notifications ;
- validation par la cliente depuis « My work » ;
- profil (photo, bio), publication avec image, j'aime, commentaire, abonnement, mise en relation, étude de cas ;
- profil public sans compte ; entreprise : talents, opportunité, candidature, messages ;
- étude de cas tirée du Studio : en attente, invisible du public, autorisée par la cliente, puis publique avec
  ses badges ; historique du Studio ; équipe de l'entreprise ; réglages (dont Settings › Email : avertissement
  d'adresse non confirmée, lien de confirmation envoyé puis ouvert) ; recherche ; page du projet ;
- Continue with Google : bouton masqué quand Google est injoignable ; avec un faux Google (même jeton signé),
  création du compte depuis la connexion, réglages (mot de passe à choisir, retrait), reconnexion, inscription
  d'une entreprise (nom et conditions exigés avant Google) ;
- AI assistants : adresse du serveur et guides, jeton personnel montré une fois et utilisable, écran d'accord
  (connexion d'abord, application et site de retour affichés, retour avec le code), application connectée listée
  puis déconnectée (jetons refusés aussitôt) ;
- pixel Meta : PageView sur la page d'accueil et à chaque écran, événements automatiques coupés, correspondance
  avancée ; inscription envoyée par le pixel et par l'API Conversions avec le même event_id ; pas de pixel sur un
  lien de partage ; aucune clé ni nom envoyé à Meta ;
- partage sans compte : lien public créé depuis Partager, visiteur en lecture seule, demande d'édition (code faux
  puis bon code), approbation depuis le lien de l'email, l'invité devient Editor sans recharger, crée une carte et
  commente, périmètre limité à une colonne, lien privé (porte, lien magique, Commenter), accès retiré vu aussitôt,
  notification au nom de l'invité, invité sur mobile ;
- mobile (iPhone 13) : tableau, glisser-déposer au doigt (appui long ; un balayage rapide fait défiler sans
  saisir la carte), carte en plein écran, menu ; thème sombre conservé ;
- administration : mauvais mot de passe refusé, connexion avec celui de `/admin`, vue en direct (6 chiffres,
  6 graphiques, valeur au survol, fil, comptes en ligne), recherche d'un compte et sa fiche, aperçu d'une
  campagne (texte saisi jamais interprété, nombre de destinataires), mobile sans défilement horizontal, aucun
  pixel Meta ;
- contenu saisi jamais interprété, aucune erreur JavaScript, aucune violation de la CSP, aucun défilement
  horizontal de la page ; captures dans `<dossier>/shots`.

Pour naviguer à la main : `setup.sh <dossier>`, puis
`php -S 127.0.0.1:8097 -t <dossier>/public_html tests/studioma/router.php` et ouvrir
`http://127.0.0.1:8097/studioma/` (administration : `…/studioma/admin/`, mot de passe `test-owner-pass`).
`sync.sh <dossier>` recopie le code après une modification (la base est gardée).
