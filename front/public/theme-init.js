// Dernière charte utilisée, appliquée avant le premier affichage (voir src/theme.ts).
// Fichier séparé plutôt que script dans index.html : la politique de sécurité (CSP) interdit les scripts en ligne.
try {
  var theme = localStorage.getItem('mes-comptes-theme');
  if (theme) document.documentElement.dataset.theme = theme;
} catch (e) {}
