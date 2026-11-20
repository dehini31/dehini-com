// Captures d'écran de la vitrine « plateformes en ligne » de la page d'accueil.
//
// Pour chaque plateforme, prend la page en ligne en entier sur ordinateur (1440 px) et sur
// téléphone (390 px), puis écrit img/projets/<slug>-desktop.webp (1200 px de large, 3000 px
// de haut au plus) et img/projets/<slug>-mobile.webp (480 px, 3100 px au plus).
// La conversion en WebP se fait dans le navigateur : seul Playwright est nécessaire.
//
//   node tools/vitrine/captures.js                  # les quatre plateformes
//   node tools/vitrine/captures.js maillage impact  # seulement celles-ci
//
// Après une nouvelle plateforme : ajouter son slug ici et sa fiche dans SHOWCASE (index.html).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SITE = 'https://dehini.com';
const SLUGS = ['impact', 'studioma', 'elbahiferti', 'maillage'];
const OUT = path.join(__dirname, '..', '..', 'img', 'projets');
const KINDS = [
  { kind: 'desktop', viewport: { width: 1440, height: 900 }, mobile: false, scale: 1, width: 1200, maxHeight: 3000 },
  { kind: 'mobile', viewport: { width: 390, height: 844 }, mobile: true, scale: 2, width: 480, maxHeight: 3100 },
];

(async () => {
  const slugs = process.argv.slice(2).length ? process.argv.slice(2) : SLUGS;
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  for (const slug of slugs) {
    for (const k of KINDS) {
      const ctx = await browser.newContext({ viewport: k.viewport, deviceScaleFactor: k.scale, isMobile: k.mobile, hasTouch: k.mobile, locale: 'fr-FR' });
      const page = await ctx.newPage();
      await page.goto(`${SITE}/${slug}/`, { waitUntil: 'networkidle', timeout: 60000 }).catch(e => console.warn(slug, k.kind, e.message.split('\n')[0]));
      // Fait défiler toute la page pour déclencher les animations d'apparition, puis revient en haut.
      const h = await page.evaluate(() => document.documentElement.scrollHeight);
      for (let y = 0; y < h; y += 300) { await page.evaluate(y => scrollTo(0, y), y); await page.waitForTimeout(120); }
      await page.evaluate(() => scrollTo(0, 0));
      await page.waitForTimeout(1500);
      const png = await page.screenshot({ fullPage: true });
      // Redimensionne, coupe et encode en WebP dans une page vierge.
      const conv = await ctx.newPage();
      const webp = await conv.evaluate(async ({ b64, width, maxHeight }) => {
        const img = new Image();
        img.src = 'data:image/png;base64,' + b64;
        await img.decode();
        const h = Math.round(img.height * width / img.width);
        const c = document.createElement('canvas');
        c.width = width; c.height = Math.min(h, maxHeight);
        const g = c.getContext('2d');
        g.imageSmoothingQuality = 'high';
        g.drawImage(img, 0, 0, width, h);
        return c.toDataURL('image/webp', 0.72).split(',')[1];
      }, { b64: png.toString('base64'), width: k.width, maxHeight: k.maxHeight });
      const file = path.join(OUT, `${slug}-${k.kind}.webp`);
      fs.writeFileSync(file, Buffer.from(webp, 'base64'));
      console.log(path.relative(process.cwd(), file), Math.round(fs.statSync(file).size / 1024), 'Ko');
      await ctx.close();
    }
  }
  await browser.close();
})();
