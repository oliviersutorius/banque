import { describe, expect, it } from 'vitest';
import { chronologique, parCategorie1, parCategorie2, totaux, versLignesVue } from './aggregate';
import type { MoisApi } from './types';

const mois: MoisApi = {
  mois: '202601',
  lignes: [
    { date: '30/01/2026', libelle: 'MTP PUTEAUX', catégorie: { catégorie1: 'À catégoriser', catégorie2: 'Non classé' }, dépense: '25,00' },
    { date: '12/01/2026', libelle: 'SPOTIFY', catégorie: { catégorie1: 'Abonnements & télécom', catégorie2: 'Streaming & musique' }, dépense: '17,20' },
    { date: '12/01/2026', libelle: 'AUCHAN', catégorie: { catégorie1: 'Alimentation', catégorie2: 'Courses' }, dépense: '2,67' },
    { date: '28/01/2026', libelle: 'FREE MOBILE', catégorie: { catégorie1: 'Abonnements & télécom', catégorie2: 'Téléphone mobile' }, dépense: '0,10' },
    { date: '05/01/2026', libelle: 'AUCHAN', catégorie: { catégorie1: 'Alimentation', catégorie2: 'Courses' }, dépense: '0,20' },
    { date: '30/01/2026', libelle: 'SALAIRE', catégorie: 'Salaire', revenu: '2645,05' },
    { date: '10/01/2026', libelle: 'MUTUELLE', catégorie: 'Remboursements santé', revenu: '50,00' },
  ],
  analyse: [],
};
const lignes = versLignesVue(mois);

describe('totaux', () => {
  it('additionne en centimes et calcule le solde', () => {
    // 25,00 + 17,20 + 2,67 + 0,10 + 0,20 = 45,17 ; 0,10 + 0,20 resterait 0,30000000000000004 en flottants
    expect(totaux(lignes)).toEqual({ dépenses: 4517, revenus: 269505, solde: 264988 });
  });

  it("vaut zéro sans ligne", () => {
    expect(totaux([])).toEqual({ dépenses: 0, revenus: 0, solde: 0 });
  });
});

describe('parCategorie1', () => {
  it('regroupe les dépenses par catégorie 1, du plus grand au plus petit', () => {
    const parts = parCategorie1(lignes, 'dépense');

    expect(parts.map((p) => [p.nom, p.cents, p.nombre])).toEqual([
      ['À catégoriser', 2500, 1],
      ['Abonnements & télécom', 1730, 2],
      ['Alimentation', 287, 2],
    ]);
    expect(parts.reduce((s, p) => s + p.part, 0)).toBeCloseTo(1);
  });

  it('regroupe les revenus par catégorie', () => {
    expect(parCategorie1(lignes, 'revenu').map((p) => p.nom)).toEqual(['Salaire', 'Remboursements santé']);
  });
});

describe('parCategorie2', () => {
  it('détaille une catégorie 1 par catégorie 2', () => {
    expect(parCategorie2(lignes, 'Abonnements & télécom').map((p) => [p.nom, p.cents])).toEqual([
      ['Streaming & musique', 1720],
      ['Téléphone mobile', 10],
    ]);
  });
});

describe('chronologique', () => {
  it("trie par date en conservant l'ordre du fichier à date égale", () => {
    expect(chronologique(lignes).map((l) => l.index)).toEqual([4, 6, 1, 2, 3, 0, 5]);
  });
});
