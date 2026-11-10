/*
 * Maillage — évite un flash de mise en page en français avant que la page ne s'affiche en
 * arabe. Chargé en premier dans <head>, sans `defer` : il s'exécute tout de suite, avant que
 * le reste de la page ne soit dessiné, et pose dir/lang sur <html> en lisant le choix de langue
 * déjà enregistré (voir assets/i18n.js, qui applique la même logique après le chargement complet).
 */
try {
  if (JSON.parse(localStorage.getItem('mlg.lang') || '"fr"') === 'ar') {
    document.documentElement.lang = 'ar';
    document.documentElement.dir = 'rtl';
    document.documentElement.classList.add('is-rtl');
  }
} catch (e) { /* stockage indisponible : la page reste en français par défaut */ }
