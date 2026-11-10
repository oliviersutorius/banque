/** Agrégations des lignes pour les vues mensuelle et annuelle. Tous les calculs sont en centimes. */

import { A_CATEGORISER, categorie1 } from './categories';
import { toCents } from './format';
import type { Categorie, LigneMois, MoisApi, Sens } from './types';

/** Une ligne prête à afficher, avec de quoi la retrouver pour la recatégoriser. */
export interface LigneVue {
  mois: string;
  index: number;
  date: string;
  libelle: string;
  sens: Sens;
  cents: number;
  catégorie: Categorie;
  marchand: string;
  type_operation: string;
}

export interface Part {
  nom: string;
  cents: number;
  nombre: number;
  /** Part du total, entre 0 et 1 */
  part: number;
}

export function sensDe(ligne: LigneMois): Sens {
  return 'dépense' in ligne ? 'dépense' : 'revenu';
}

export function versLignesVue(mois: MoisApi): LigneVue[] {
  return mois.lignes.map((l, index) => {
    const sens = sensDe(l);
    return {
      mois: mois.mois,
      index,
      date: l.date,
      libelle: l.libelle,
      sens,
      cents: toCents('dépense' in l ? l.dépense : l.revenu),
      catégorie: l.catégorie,
      marchand: mois.analyse[index]?.marchand ?? '',
      type_operation: mois.analyse[index]?.type_operation ?? '',
    };
  });
}

export function totaux(lignes: LigneVue[]): { dépenses: number; revenus: number; solde: number } {
  let dépenses = 0;
  let revenus = 0;
  for (const l of lignes) {
    if (l.sens === 'dépense') dépenses += l.cents;
    else revenus += l.cents;
  }
  return { dépenses, revenus, solde: revenus - dépenses };
}

function regrouper(lignes: LigneVue[], cle: (l: LigneVue) => string): Part[] {
  const groupes = new Map<string, { cents: number; nombre: number }>();
  let total = 0;
  for (const l of lignes) {
    const nom = cle(l);
    const g = groupes.get(nom) ?? { cents: 0, nombre: 0 };
    g.cents += l.cents;
    g.nombre += 1;
    groupes.set(nom, g);
    total += l.cents;
  }
  return [...groupes.entries()]
    .map(([nom, g]) => ({ nom, ...g, part: total === 0 ? 0 : g.cents / total }))
    .sort((a, b) => b.cents - a.cents || a.nom.localeCompare(b.nom, 'fr'));
}

/** Montant par catégorie 1 pour un sens, du plus grand au plus petit. */
export function parCategorie1(lignes: LigneVue[], sens: Sens): Part[] {
  return regrouper(
    lignes.filter((l) => l.sens === sens),
    (l) => categorie1(l.catégorie),
  );
}

/** Détail d'une catégorie 1 de dépense par catégorie 2. */
export function parCategorie2(lignes: LigneVue[], cat1: string): Part[] {
  return regrouper(
    lignes.filter((l) => l.sens === 'dépense' && categorie1(l.catégorie) === cat1),
    (l) => (typeof l.catégorie === 'string' ? A_CATEGORISER : l.catégorie.catégorie2),
  );
}

const dateTriable = (date: string) => date.slice(6, 10) + date.slice(3, 5) + date.slice(0, 2);

/** Ordre chronologique ; à date égale, l'ordre d'origine est conservé (tri stable). */
export function chronologique(lignes: LigneVue[]): LigneVue[] {
  return [...lignes].sort((a, b) => dateTriable(a.date).localeCompare(dateTriable(b.date)) || a.mois.localeCompare(b.mois) || a.index - b.index);
}

export interface LigneMatrice {
  nom: string;
  /** Montant par mois de janvier à décembre ; null si le mois n'a pas de fichier */
  parMois: (number | null)[];
  total: number;
}

export interface Matrice {
  lignes: LigneMatrice[];
  /** Total de chaque mois ; null si le mois n'a pas de fichier */
  totauxMois: (number | null)[];
  total: number;
}

/**
 * Matrice catégories 1 (lignes) × mois de janvier à décembre (colonnes) pour une année et un sens.
 * Les catégories sont triées par total décroissant.
 */
export function matrice(lignes: LigneVue[], sens: Sens, annee: string, moisEnregistres: ReadonlySet<string>): Matrice {
  const colonnes = Array.from({ length: 12 }, (_, i) => `${annee}${String(i + 1).padStart(2, '0')}`);
  const vide = (): (number | null)[] => colonnes.map((m) => (moisEnregistres.has(m) ? 0 : null));

  const parCategorie = new Map<string, (number | null)[]>();
  const totauxMois = vide();
  for (const l of lignes) {
    const col = colonnes.indexOf(l.mois);
    if (l.sens !== sens || col < 0) continue;
    const nom = categorie1(l.catégorie);
    const ligne = parCategorie.get(nom) ?? vide();
    ligne[col] = (ligne[col] ?? 0) + l.cents;
    parCategorie.set(nom, ligne);
    totauxMois[col] = (totauxMois[col] ?? 0) + l.cents;
  }

  const somme = (valeurs: (number | null)[]) => valeurs.reduce<number>((s, v) => s + (v ?? 0), 0);
  return {
    lignes: [...parCategorie.entries()]
      .map(([nom, parMois]) => ({ nom, parMois, total: somme(parMois) }))
      .sort((a, b) => b.total - a.total || a.nom.localeCompare(b.nom, 'fr')),
    totauxMois,
    total: somme(totauxMois),
  };
}
