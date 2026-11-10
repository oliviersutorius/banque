const NOMS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « 202601 » → « Janvier 2026 » */
export function libelleMois(mois: string): string {
  const nom = NOMS[Number(mois.slice(4, 6)) - 1] ?? mois.slice(4, 6);
  return `${nom.charAt(0).toUpperCase()}${nom.slice(1)} ${mois.slice(0, 4)}`;
}
