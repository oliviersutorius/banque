/** Client HTTP de l'API PHP. Toutes les routes sont sous /api. */

import type {
  ApercuImport,
  Categories,
  LigneAImporter,
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
