const NOMS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « 202601 » → « Janvier 2026 » */
export function libelleMois(mois: string): string {
  const nom = NOMS[Number(mois.slice(4, 6)) - 1] ?? mois.slice(4, 6);
  return `${nom.charAt(0).toUpperCase()}${nom.slice(1)} ${mois.slice(0, 4)}`;
}

/** Abréviations des mois pour les en-têtes de colonnes */
export const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
