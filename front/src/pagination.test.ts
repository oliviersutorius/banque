import { describe, expect, it } from 'vitest';
import { paginer } from './pagination';

const liste = Array.from({ length: 60 }, (_, i) => i + 1);

describe('paginer', () => {
  it('découpe en pages de 25', () => {
    const p = paginer(liste, 1, 25);
    expect(p.elements).toHaveLength(25);
    expect(p.nbPages).toBe(3);
    expect([p.debut, p.fin]).toEqual([1, 25]);
  });

  it('donne une dernière page partielle', () => {
    const p = paginer(liste, 3, 25);
    expect(p.elements).toEqual([51, 52, 53, 54, 55, 56, 57, 58, 59, 60]);
    expect([p.debut, p.fin]).toEqual([51, 60]);
  });

  it('ramène une page hors bornes dans les limites', () => {
    expect(paginer(liste, 9, 25).page).toBe(3);
    expect(paginer(liste, 0, 25).page).toBe(1);
  });

  it('gère une liste vide', () => {
    const p = paginer([], 2, 25);
    expect(p).toEqual({ elements: [], page: 1, nbPages: 1, debut: 0, fin: 0 });
  });
});
