import type { Theme } from './types';

export const THEMES: { valeur: Theme; nom: string; description: string; apercu: string[] }[] = [
  { valeur: 'guimauve', nom: 'Guimauve', description: 'Pastel, doux et lumineux', apercu: ['#FFF8F5', '#F49AC1', '#F4A3A8', '#8FD3B6'] },
  { valeur: 'sauge', nom: 'Sauge & Lin', description: 'Naturel et apaisant', apercu: ['#F6F3EC', '#7FA37A', '#C98A6B', '#D9B38C'] },
  { valeur: 'nuit', nom: 'Nuit Lavande', description: 'Sombre et douce', apercu: ['#1E1B2E', '#B8A4F4', '#F59EBB', '#86E3C3'] },
];

/** Applique la charte graphique à toute la page (variables CSS sur [data-theme]). */
export function appliquerTheme(theme: Theme): void {
  document.documentElement.dataset.theme = theme;
}
