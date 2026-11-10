# Tests de Maillage

Faux hébergement local qui reproduit cPanel : `public_html/maillage/` (copie du code), `public_html/admin/`
(connexion du propriétaire, mot de passe `test-owner-pass`) et `maillage-data/` créé à côté, comme sur le serveur.
Les emails sont écrits dans `mail.jsonl` au lieu d'être envoyés (`MLG_MAIL_LOG`). Le routeur `router.php` applique
les mêmes interdictions et les mêmes en-têtes que `maillage/.htaccess`, dont la politique de sécurité (CSP).

```bash
tests/maillage/run.sh /tmp/mlg-test
```

`run.sh` prépare un hébergement neuf, lance le serveur PHP (port 8096) et exécute `e2e.js` (Playwright / Chromium) :

- landing : chiffres en direct, formulaire d'inscription (mot de passe trop court, conditions à accepter), création
  de l'espace d'une marque en attente de validation, email de bienvenue ;
- espace entreprise : produit avec 6 déclinaisons et une photo, point de vente placé d'un clic sur la carte, stock ;
- le produit reste invisible tant que le propriétaire n'a pas validé l'inscription dans `admin.html` ;
- client (iPhone 13) : carte, recherche, fiche produit, disponibilité par taille et couleur, réservation, suivi ;
- traitement de la commande (confirmée → mise de côté → retirée), stock mis de côté puis rétabli à l'annulation,
  commande au-delà du stock refusée, suivi refusé avec un mauvais téléphone ;
- distributeur : inscription, partenariat demandé puis accepté, catalogue de la marque référencé dans son magasin ;
- cloisonnement : un compte ne modifie ni ne lit ce qui appartient à une autre entreprise ;
- analyses de la marque (commandes, chiffre d'affaires hors annulation, recherches, réseau) ;
- mot de passe oublié (lien reçu par email, anciennes sessions fermées), espace ouvert depuis l'administration,
  suppression de la démonstration ;
- démonstration en lecture seule ; fichiers internes interdits ; écritures refusées sans en-tête ni jeton ;
- aucune erreur JavaScript, aucune violation de la CSP, aucun défilement horizontal ; captures dans `<dossier>/shots`.

Pour naviguer à la main : `setup.sh <dossier>`, puis
`php -S 127.0.0.1:8095 -t <dossier>/public_html tests/maillage/router.php` et ouvrir
`http://127.0.0.1:8095/maillage/`. `sync.sh <dossier>` recopie le code après une modification (la base est gardée).
