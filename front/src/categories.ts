/** Aides autour des catégories : libellés, comparaison, règles. */

import type { Categorie, Categories, RegleSaisie, Sens } from './types';

export const A_CATEGORISER = 'À catégoriser';

export function categorie1(categorie: Categorie): string {
  return typeof categorie === 'string' ? categorie : categorie.catégorie1;
}

export function estACategoriser(categorie: Categorie): boolean {
  return categorie1(categorie) === A_CATEGORISER;
}

export function libelleCategorie(categorie: Categorie): string {
  return typeof categorie === 'string' ? categorie : `${categorie.catégorie1} › ${categorie.catégorie2}`;
}

export function memeCategorie(a: Categorie, b: Categorie): boolean {
  return libelleCategorie(a) === libelleCategorie(b);
}

export function icone(categories: Categories, sens: Sens, nom: string): string {
  const liste = sens === 'dépense' ? categories.dépenses : categories.revenus;
  return liste.find((c) => c.nom === nom)?.icone ?? '❔';
}

/** Valeur de <select> pour une catégorie, et l'inverse. */
export function versOption(categorie: Categorie): string {
  return typeof categorie === 'string' ? categorie : `${categorie.catégorie1}\u001f${categorie.catégorie2}`;
}

export function depuisOption(valeur: string, sens: Sens): Categorie {
  if (sens === 'revenu') {
    return valeur;
  }
  const [catégorie1 = '', catégorie2 = ''] = valeur.split('\u001f');
  return { catégorie1, catégorie2 };
}

export function regle(motif: string, sens: Sens, categorie: Categorie, typeOperation?: string): RegleSaisie {
  const base: RegleSaisie =
    typeof categorie === 'string'
      ? { motif, type: sens, catégorie: categorie }
      : { motif, type: sens, catégorie1: categorie.catégorie1, catégorie2: categorie.catégorie2 };
  return typeOperation ? { ...base, type_operation: typeOperation } : base;
}
