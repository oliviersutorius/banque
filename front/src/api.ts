/** Client HTTP de l'API PHP. Toutes les routes sont sous /api. */

import type {
  AnneeApi,
  ApercuImport,
  Categories,
  Categorie,
  LigneAImporter,
  MoisApi,
  Regle,
  RegleSaisie,
  ResultatApprentissage,
  ResultatImport,
  Sens,
} from './types';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch('/api' + path, {
    ...init,
    headers: init.body instanceof FormData ? init.headers : { 'Content-Type': 'application/json', ...init.headers },
  });
  const body: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const details = (body ?? {}) as Record<string, unknown>;
    const message = typeof details.erreur === 'string' ? details.erreur : `Erreur HTTP ${response.status}`;
    throw new ApiError(response.status, message, details);
  }
  return body as T;
}

const post = <T>(path: string, body: unknown) => api<T>(path, { method: 'POST', body: JSON.stringify(body) });

export interface Sante {
  statut: string;
  php: string;
}

export const getSante = () => api<Sante>('/sante');
export const getCategories = () => api<Categories>('/categories');
export const getRegles = () => api<Regle[]>('/regles');

export const getMoisDisponibles = () => api<string[]>('/mois');
export const getMois = (mois: string) => api<MoisApi>(`/mois/${mois}`);
export const getAnnee = (annee: string) => api<AnneeApi>(`/annee/${annee}`);

export interface LigneVisee {
  index: number;
  date: string;
  libelle: string;
}

/** Recatégorise une ligne et, en une seule écriture, les lignes similaires du même mois. */
export const recategoriser = (mois: string, ligne: LigneVisee, catégorie: Categorie, autres: LigneVisee[] = []) =>
  api<MoisApi>(`/mois/${mois}/lignes/${ligne.index}`, {
    method: 'PATCH',
    body: JSON.stringify({ date: ligne.date, libelle: ligne.libelle, catégorie, autres }),
  });

export function previewImport(fichier: File): Promise<ApercuImport> {
  const form = new FormData();
  form.append('fichier', fichier);
  return api<ApercuImport>('/import/preview', { method: 'POST', body: form });
}

export const confirmImport = (lignes: LigneAImporter[]) => post<ResultatImport>('/import/confirm', { lignes });

export const apprendreRegle = (règle: RegleSaisie, appliquer_aux_mois: boolean) =>
  post<ResultatApprentissage>('/regles', { règle, appliquer_aux_mois });

export const correspondances = (règle: RegleSaisie, lignes: { libelle: string; sens: Sens }[]) =>
  post<{ correspondances: boolean[] }>('/regles/correspondances', { règle, lignes });
