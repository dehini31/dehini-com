# Tests de SWANBIDA

Faux hébergement local qui reproduit cPanel : `public_html/swanbida/` (copie du code), `public_html/admin/config.php`
(mot de passe propriétaire `test-owner-pass`) et `swanbida-data/` créé à côté, comme sur le serveur. Une fausse API
Meta (`fake-services.php`) enregistre tout ce que la boutique envoie ; les emails sont écrits dans `mail.jsonl` au
lieu d'être envoyés (`SWB_MAIL_LOG`).

```bash
tests/swanbida/run.sh /tmp/swb-test
```

`run.sh` prépare un hébergement neuf, lance les deux serveurs PHP (ports 8090 et 8091), configure le pixel et
les coordonnées dans l'admin, puis exécute `e2e.js` (Playwright / Chromium, iPhone 13) :

- commande mobile en français puis en arabe depuis une URL de publicité Meta (UTM, IDs de campagne, `fbclid`) ;
- PageView, ViewContent, AddToCart, InitiateCheckout envoyés par le pixel ET par le serveur avec le même `event_id` ;
- Lead serveur unique (`lead.<n°>`), valeur, `fbc`, données hachées ;
- aucune conversion à l'ouverture ni au rafraîchissement de la page de remerciement ;
- connexion à l'admin, confirmation de la commande → un seul Purchase (`purchase.<n°>`) côté serveur ;
- arabe sur `/` (RTL), français sur `/fr/`, `hreflang` x-default, redirection 301 des anciennes adresses `/ar/…` ;
- commandes abandonnées : enregistrement dès qu'un téléphone valide est tapé, rien avant ; ligne fermée quand la
  commande est envoyée ; alerte email unique par le cron ; Appeler / note / « Contactée » / « Créer la commande »
  dans l'admin → Lead serveur avec le `fbc` de la publicité d'origine, pas de doublon au double clic ;
- absence d'erreur JavaScript et de défilement horizontal (boutique et admin) ; captures d'écran dans `<dossier>/shots`.

Pour naviguer à la main : `setup.sh <dossier>`, puis
`php -S 127.0.0.1:8090 -t <dossier>/public_html tests/swanbida/router.php` et ouvrir
`http://127.0.0.1:8090/swanbida/`. `sync.sh <dossier>` recopie le code après une modification.
