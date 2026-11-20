# Tests de Studioma

Faux hébergement local qui reproduit cPanel : `public_html/studioma/` (copie du code) et `studioma-data/` créé à
côté, comme sur le serveur. Les emails sont écrits dans `mail.jsonl` au lieu d'être envoyés (`STM_MAIL_LOG`). Le
routeur `router.php` applique les mêmes interdictions et les mêmes en-têtes que `studioma/.htaccess`, dont la
politique de sécurité (CSP).

```bash
tests/studioma/run.sh /tmp/stm-test
```

`run.sh` prépare un hébergement neuf, lance le serveur PHP (port 8097) et exécute quatre batteries, chacune sur une
base vide :

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
- partage sans compte : lien public créé depuis Partager, visiteur en lecture seule, demande d'édition (code faux
  puis bon code), approbation depuis le lien de l'email, l'invité devient Editor sans recharger, crée une carte et
  commente, périmètre limité à une colonne, lien privé (porte, lien magique, Commenter), accès retiré vu aussitôt,
  notification au nom de l'invité, invité sur mobile ;
- mobile (iPhone 13) : tableau, glisser-déposer au doigt (appui long ; un balayage rapide fait défiler sans
  saisir la carte), carte en plein écran, menu ; thème sombre conservé ;
- contenu saisi jamais interprété, aucune erreur JavaScript, aucune violation de la CSP, aucun défilement
  horizontal de la page ; captures dans `<dossier>/shots`.

Pour naviguer à la main : `setup.sh <dossier>`, puis
`php -S 127.0.0.1:8097 -t <dossier>/public_html tests/studioma/router.php` et ouvrir
`http://127.0.0.1:8097/studioma/`. `sync.sh <dossier>` recopie le code après une modification (la base est gardée).
