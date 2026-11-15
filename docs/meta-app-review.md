# Nouvelle app Meta (chatgptapp) : migration, réglages et examen par Meta

Guide pour passer le tableau de bord marketing (`dehini.com/marketing`) de l'ancienne app Meta
(« ads analyse ») à la nouvelle (« chatgptapp », ID `3025910297765050`), puis la faire valider par Meta.
Le tableau de bord continue avec l'ancienne app jusqu'au clic sur « Basculer » ; aucune donnée n'est
modifiée (messages, commentaires, publications, campagnes et leads sont identifiés par leur numéro chez
Meta, le même quelle que soit l'app : pas de doublon, rien d'écrasé).

## 1. Dans la nouvelle app (developers.facebook.com)

| Où | Quoi |
|---|---|
| Paramètres › Général | Domaine `dehini.com` ; URL de la politique de confidentialité `https://dehini.com/marketing/confidentialite.html` ; URL des instructions de suppression des données `https://dehini.com/marketing/suppression-donnees.html` ; icône 1024×1024 ; catégorie « Entreprise et pages » ; e-mail de contact |
| Facebook Login for Business › Paramètres | URI de redirection OAuth valide : `https://dehini.com/marketing/meta.php` |
| Facebook Login for Business › Configurations | Créer une configuration : type de jeton **« Jeton d'accès d'utilisateur système »**, expiration **« Jamais »** (aucun renouvellement à faire), avec toutes les autorisations du tableau ci-dessous et les ressources : Page YINVESTI, compte Instagram @yinvesti_, comptes publicitaires |
| Cas d'utilisation | Messenger (`pages_messaging`, `pages_manage_metadata`), API Instagram (messages, commentaires, statistiques), gestion de la Page, publicité (`ads_read`, `leads_retrieval`) |
| Webhooks | Fait automatiquement à la bascule. À la main si besoin : objet **Page** (champs `messages`, `message_echoes`, `feed`) et objet **Instagram** (`comments`, `messages`), URL de rappel `https://dehini.com/marketing/webhook.php`, jeton de vérification = `MK_META_WEBHOOK_VERIFY_TOKEN` de config.php |

## 2. Sur le serveur : `~/public_html/marketing/config.php` (jamais dans GitHub)

```php
define('MK_META_NEXT_APP_ID', '3025910297765050');
define('MK_META_NEXT_APP_SECRET', '… clé secrète (Paramètres › Général › Afficher) …');
define('MK_META_NEXT_CONFIG_ID', '… ID de la configuration Facebook Login for Business …');
define('MK_META_WEBHOOK_VERIFY_TOKEN', '… texte secret au choix, 20 caractères ou plus …');
```

La clé secrète n'est jamais affichée ni envoyée au navigateur ; les jetons Meta sont chiffrés
(AES-256-GCM, clé hors du dossier public).

## 3. Dans le tableau de bord : Connexions › Nouvelle app Meta

1. **La nouvelle app** : l'ID et la clé de config.php sont reconnus (ou à saisir).
2. **Connexion** : « Se connecter avec la nouvelle app », cocher la Page, l'Instagram et les comptes
   publicitaires, accepter toutes les autorisations.
3. **Page et Instagram** : la même Page que l'app actuelle est choisie d'office.
4. **Tests** : app, connexion (et son expiration), autorisations, Page, statistiques, publications et
   commentaires, messages Messenger et Instagram, compte publicitaire, Lead Ads, adresse de webhooks
   et abonnements. « Copier le rapport » pour l'envoyer.
5. **Basculer** : la nouvelle app devient l'app du tableau de bord (l'ancienne est désabonnée des
   webhooks de la Page, la nouvelle y est abonnée). « Revenir à l'ancienne app » reste possible.

Après la bascule, quand tout fonctionne, on peut retirer l'ancienne app de la Page (Facebook ›
Paramètres › Intégrations professionnelles) puis renommer `MK_META_NEXT_APP_ID` / `MK_META_NEXT_APP_SECRET`
en `MK_META_APP_ID` / `MK_META_APP_SECRET` (le tableau de bord passe tout seul de l'un à l'autre).

## 4. Examen par Meta (accès avancé) puis mode Live

En mode Développement (« Prête pour le test »), l'app lit déjà la Page et le compte Instagram de
l'administrateur. Pour recevoir **en temps réel** les messages et commentaires de tout le monde et voir les
noms des auteurs de commentaires, il faut l'**accès avancé** puis le **mode Live** :

1. **Vérification de l'entreprise** (Business Manager › Centre de sécurité).
2. **Examen de l'app › Autorisations et fonctionnalités** : « Demander l'accès avancé » pour chaque
   autorisation ci-dessous, avec le texte et une vidéo (screencast) montrant l'usage dans le tableau de bord.
3. Une fois accepté : passer l'app en **Live**, puis Connexions › Temps réel › « Réactiver ».

### Textes à copier (anglais, langue de l'examen)

| Autorisation | Usage (à coller dans « How will your app use this permission? ») |
|---|---|
| pages_show_list | Lists the Facebook Pages the business owner manages so they can pick the YINVESTI Page whose data is shown in their private marketing dashboard (dehini.com/marketing). |
| pages_read_engagement | Reads the YINVESTI Page's posts, reactions, comment counts and Page metrics to display engagement statistics in the business's internal marketing dashboard. |
| pages_read_user_content | Reads comments left on the YINVESTI Page's posts (including the author's name) so the marketing team can see questions from prospective investors and answer them. |
| read_insights | Reads Page insights (reach, views, follows) to build daily, weekly and monthly marketing reports for the YINVESTI team. |
| pages_manage_metadata | Subscribes the YINVESTI Page to webhooks so new messages and comments appear in the dashboard in real time, and reads the Page's conversations list. |
| pages_messaging | Reads Messenger conversations of the YINVESTI Page (read only, no message is sent by the app) so the team can see who is waiting for an answer and turn interested people into leads. |
| instagram_basic | Reads the YINVESTI Instagram professional account profile and its media list to display posts and their engagement in the dashboard. |
| instagram_manage_comments | Reads comments on the YINVESTI Instagram account's posts (read only) so the team can follow questions and answer them on Instagram. |
| instagram_manage_insights | Reads Instagram account and media insights (reach, views, interactions) for the marketing reports. |
| instagram_manage_messages | Reads Instagram Direct conversations of the YINVESTI account (read only, no message is sent) to list unanswered messages and create leads. |
| ads_read | Reads results of the business's own Meta Ads campaigns (spend, impressions, clicks, leads) to compare campaigns in the marketing dashboard. |
| leads_retrieval | Retrieves leads submitted through the YINVESTI Page's Lead Ads forms into the business's private lead list so sales can call them back. |
| business_management | Lists the ad accounts and assets owned by the business in Business Manager so the owner can choose which ones the dashboard reads. |

### Vidéos (une par autorisation, ou une vidéo qui montre tout)

1. Ouvrir `https://dehini.com/marketing`, se connecter.
2. Connexions › « Se connecter avec Facebook » : montrer la fenêtre de Meta et les autorisations accordées.
3. Leads & Contacts › **Messages** (pages_messaging, instagram_manage_messages, pages_manage_metadata) :
   ouvrir une conversation, montrer « Lire » et « Créer le prospect ».
4. Leads & Contacts › **Commentaires** (pages_read_user_content, instagram_manage_comments) : un commentaire
   et son auteur, la réponse de la Page.
5. Social Media et Communication (pages_read_engagement, read_insights, instagram_basic,
   instagram_manage_insights) : relevés et publications.
6. Ads Manager (ads_read, business_management) et Leads (leads_retrieval) : campagnes et prospects importés.
7. Rappeler à l'écran que l'app n'envoie aucun message et ne publie rien (lecture seule).
