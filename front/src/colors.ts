/**
 * Couleurs des catégories dans les graphiques.
 *
 * Chaque charte définit 12 couleurs (--palette-0 à --palette-11). Une catégorie reçoit toujours la même,
 * d'après sa position dans categories.json ; « À catégoriser » est toujours en gris.
 */

import { A_CATEGORISER } from './categories';
import type { Categories, Sens } from './types';

export const TAILLE_PALETTE = 12;

export function indexCouleur(categories: Categories, sens: Sens, nom: string): number | null {
  if (nom === A_CATEGORISER) {
    return null;
  }
  const liste = sens === 'dépense' ? categories.dépenses : categories.revenus;
  const i = liste.findIndex((c) => c.nom === nom);
  return i < 0 ? null : i % TAILLE_PALETTE;
}

export function couleur(categories: Categories, sens: Sens, nom: string): string {
  const i = indexCouleur(categories, sens, nom);
  return i === null ? 'var(--uncategorized)' : `var(--palette-${i})`;
}

/** Couleur d'une catégorie 2 : nuance de celle de sa catégorie 1. */
export function couleurSousCategorie(base: string, rang: number): string {
  const opacite = Math.max(35, 100 - rang * 15);
  return `color-mix(in srgb, ${base} ${opacite}%, var(--card))`;
}
