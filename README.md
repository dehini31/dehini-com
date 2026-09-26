# dehini.com

Site personnel de Mohammed Dehini — page unique statique (`index.html`, sans build).

Contenu à modifier : listes `EXPERIENCES`, `PROJECTS`, `SKILLS` en haut du `<script>` d'`index.html`.

## Mise en ligne (serveur dzSecurity, compte cPanel `dehini91`)

Le dépôt est cloné dans `~/dehini-com` et `index.html` est copié dans `~/public_html/`.
Une tâche cron (cPanel → Tâches Cron) met en ligne chaque modification poussée sur `main` :

```
*/5 * * * * cd ~/dehini-com && git pull -q && cp -f index.html ~/public_html/index.html
```
