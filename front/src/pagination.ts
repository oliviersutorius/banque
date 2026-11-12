export interface Page<T> {
  elements: T[];
  /** Page affichée (1-based), ramenée dans [1, nbPages] */
  page: number;
  nbPages: number;
  /** Rang (1-based) du premier et du dernier élément affichés ; 0 si la liste est vide */
  debut: number;
  fin: number;
}

/** Découpe une liste en pages de `taille` éléments. Une page hors bornes est ramenée à la plus proche. */
export function paginer<T>(liste: readonly T[], page: number, taille: number): Page<T> {
  const nbPages = Math.max(1, Math.ceil(liste.length / taille));
  const courante = Math.min(Math.max(1, Math.trunc(page)), nbPages);
  const depart = (courante - 1) * taille;
  const elements = liste.slice(depart, depart + taille);
  return {
    elements,
    page: courante,
    nbPages,
    debut: elements.length === 0 ? 0 : depart + 1,
    fin: depart + elements.length,
  };
}
