import { describe, expect, it } from 'vitest';
import { lignesAEnvoyer, ordonnerLignes, totauxNouvelles } from './importLogic';
import type { ApercuImport, LigneApercu } from './types';

function ligne(id: number, date: string, extra: Partial<LigneApercu> = {}): LigneApercu {
  return {
    id,
    ligne: id + 2,
    date,
    libelle: `ligne ${id}`,
    sens: 'dépense',
    montant: '1,00',
    catégorie: { catégorie1: 'Alimentation', catégorie2: 'Courses' },
    type_operation: 'CARTE',
    marchand: 'X',
    source: 'règle',
    motif: 'X',
    à_revoir: false,
    doublon: false,
    ...extra,
  };
}

describe('ordonnerLignes', () => {
  it('met les lignes à revoir en tête, les doublons à la fin, et trie par date', () => {
    const lignes = [
      ligne(0, '30/01/2026'),
      ligne(1, '12/01/2026', { doublon: true }),
      ligne(2, '28/01/2026', { à_revoir: true }),
      ligne(3, '12/01/2026'),
      ligne(4, '05/01/2026', { à_revoir: true }),
    ];

    expect(ordonnerLignes(lignes).map((l) => l.id)).toEqual([4, 2, 3, 0, 1]);
  });

  it("conserve l'ordre du CSV à date égale", () => {
    const lignes = [ligne(0, '12/01/2026'), ligne(1, '12/01/2026'), ligne(2, '12/01/2026')];

    expect(ordonnerLignes(lignes).map((l) => l.id)).toEqual([0, 1, 2]);
  });
});

describe('lignesAEnvoyer', () => {
  it('envoie toutes les lignes avec les catégories corrigées', () => {
    const apercu = {
      mois: [{ mois: '202601', existant: false, lignes: [ligne(0, '12/01/2026'), ligne(1, '13/01/2026', { doublon: true })] }],
    } as ApercuImport;

    const envoi = lignesAEnvoyer(apercu, new Map([[1, { catégorie1: 'Shopping', catégorie2: 'Beauté' }]]));

    expect(envoi).toHaveLength(2);
    expect(envoi[0]).toEqual({
      date: '12/01/2026',
      libelle: 'ligne 0',
      sens: 'dépense',
      montant: '1,00',
      catégorie: { catégorie1: 'Alimentation', catégorie2: 'Courses' },
    });
    expect(envoi[1]?.catégorie).toEqual({ catégorie1: 'Shopping', catégorie2: 'Beauté' });
  });
});

describe('totauxNouvelles', () => {
  it('additionne en centimes sans compter les doublons', () => {
    const lignes = [
      ligne(0, '12/01/2026', { montant: '0,10' }),
      ligne(1, '12/01/2026', { montant: '0,20' }),
      ligne(2, '12/01/2026', { montant: '2645,05', sens: 'revenu', catégorie: 'Salaire' }),
      ligne(3, '12/01/2026', { montant: '99,99', doublon: true }),
    ];

    expect(totauxNouvelles(lignes)).toEqual({ dépenses: 30, revenus: 264505 });
  });
});
