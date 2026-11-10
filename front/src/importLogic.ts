/** Logique pure de l'écran d'import (testée avec Vitest). */

import type { ApercuImport, Categorie, LigneAImporter, LigneApercu } from './types';
import { toCents } from './format';

const dateTriable = (date: string) => date.slice(6, 10) + date.slice(3, 5) + date.slice(0, 2);

/**
 * Ordre d'affichage d'un mois : nouvelles lignes à revoir d'abord, puis les autres nouvelles,
 * puis les doublons ; chronologique à l'intérieur de chaque groupe (ordre du CSV à date égale).
 */
export function ordonnerLignes(lignes: LigneApercu[]): LigneApercu[] {
  const groupe = (l: LigneApercu) => (l.doublon ? 2 : l.à_revoir ? 0 : 1);
  return lignes
    .map((ligne, i) => ({ ligne, i }))
    .sort(
      (a, b) =>
        groupe(a.ligne) - groupe(b.ligne) ||
        dateTriable(a.ligne.date).localeCompare(dateTriable(b.ligne.date)) ||
        a.i - b.i,
    )
    .map(({ ligne }) => ligne);
}

/**
 * Toutes les lignes du CSV, nouvelles et doublons, avec les catégories corrigées :
 * le serveur refait la fusion et n'ajoute que les nouvelles.
 */
export function lignesAEnvoyer(apercu: ApercuImport, corrections: ReadonlyMap<number, Categorie>): LigneAImporter[] {
  return apercu.mois.flatMap((m) =>
    m.lignes.map((l) => ({
      date: l.date,
      libelle: l.libelle,
      sens: l.sens,
      montant: l.montant,
      catégorie: corrections.get(l.id) ?? l.catégorie,
    })),
  );
}

/** Totaux en centimes des nouvelles lignes d'un mois. */
export function totauxNouvelles(lignes: LigneApercu[]): { dépenses: number; revenus: number } {
  return lignes
    .filter((l) => !l.doublon)
    .reduce(
      (t, l) => (l.sens === 'dépense' ? { ...t, dépenses: t.dépenses + toCents(l.montant) } : { ...t, revenus: t.revenus + toCents(l.montant) }),
      { dépenses: 0, revenus: 0 },
    );
}
