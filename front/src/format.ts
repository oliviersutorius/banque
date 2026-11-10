/** Conversions entre les montants du JSON ("1234,50") et les centimes entiers. */

export function toCents(amount: string): number {
  const match = /^(\d+),(\d{2})$/.exec(amount);
  if (!match) {
    throw new Error(`Montant invalide : « ${amount} »`);
  }
  return Number(match[1]) * 100 + Number(match[2]);
}

/** Formate des centimes en euros à la française : 123456 → « 1 234,56 € ». */
export function formatEuros(cents: number): string {
  const sign = cents < 0 ? '-' : '';
  const abs = Math.abs(cents);
  const euros = Math.floor(abs / 100)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  const rest = (abs % 100).toString().padStart(2, '0');
  return `${sign}${euros},${rest} €`;
}
