import { describe, expect, it } from 'vitest';
import { formatEuros, toCents } from './format';

describe('toCents', () => {
  it('convertit un montant du JSON en centimes', () => {
    expect(toCents('69,90')).toBe(6990);
    expect(toCents('2645,05')).toBe(264505);
    expect(toCents('0,07')).toBe(7);
  });

  it('refuse un montant mal formé', () => {
    expect(() => toCents('69,9')).toThrow();
    expect(() => toCents('1 234,00')).toThrow();
  });
});

describe('formatEuros', () => {
  it('formate à la française avec séparateur de milliers', () => {
    expect(formatEuros(123456)).toBe('1 234,56 €');
    expect(formatEuros(5)).toBe('0,05 €');
    expect(formatEuros(-264505)).toBe('-2 645,05 €');
  });
});
