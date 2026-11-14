/** Client HTTP de l'API PHP. Toutes les routes sont sous /api. */

import { EVENEMENT_SESSION, enteteCsrf, estRouteDeConnexion, memoriserSession, oublierSession, type EtatSession } from './auth';
import type {
  AnneeApi,
  ApercuImport,
  Categories,
  Categorie,
  LigneAImporter,
  MoisApi,
  MoisATrier,
  Regle,
  RegleSaisie,
  ResultatApprentissage,
  ResultatImport,
  Sens,
  Theme,
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
  const headers = {
    ...(init.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
    ...enteteCsrf(init.method),
    ...(init.headers as Record<string, string> | undefined),
  };
  const response = await fetch('/api' + path, { ...init, headers, credentials: 'same-origin' });
  const body: unknown = await response.json().catch(() => null);
  if (response.status === 401 && !estRouteDeConnexion(path)) {
    // Session expirée ou fermée ailleurs : App affiche la page de connexion
    oublierSession();
    window.dispatchEvent(new Event(EVENEMENT_SESSION));
  }
  if (!response.ok) {
    const details = (body ?? {}) as Record<string, unknown>;
    const message = typeof details.erreur === 'string' ? details.erreur : `Erreur HTTP ${response.status}`;
    throw new ApiError(response.status, message, details);
  }
  return body as T;
}

const post = <T>(path: string, body: unknown) => api<T>(path, { method: 'POST', body: JSON.stringify(body) });

/** Événement émis après chaque écriture qui peut changer les lignes « À catégoriser » (badge « À trier ») */
export const EVENEMENT_DONNEES = 'mes-comptes:donnees';

async function ecriture<T>(requete: Promise<T>): Promise<T> {
  const resultat = await requete;
  window.dispatchEvent(new Event(EVENEMENT_DONNEES));
  return resultat;
}

export interface Sante {
  statut: string;
}

export const getSession = () => api<EtatSession>('/session').then(memoriserSession);
export const connexion = (identifiant: string, mot_de_passe: string) =>
  post<EtatSession>('/connexion', { identifiant, mot_de_passe }).then(memoriserSession);
export const connexionCode = (code: string) => post<EtatSession>('/connexion/code', { code }).then(memoriserSession);
export async function deconnexion(): Promise<void> {
  try {
    await api('/deconnexion', { method: 'POST' });
  } finally {
    oublierSession();
  }
}

export const getSante = () => api<Sante>('/sante');
export const getCategories = () => api<Categories>('/categories');
export const getRegles = () => api<Regle[]>('/regles');

export const getMoisDisponibles = () => api<string[]>('/mois');
export const getMois = (mois: string) => api<MoisApi>(`/mois/${mois}`);
export const getAnnee = (annee: string) => api<AnneeApi>(`/annee/${annee}`);
export const getATrier = () => api<MoisATrier[]>('/a-trier');

/**
 * Recatégorise une opération et, en une seule écriture, d'autres opérations (lignes similaires).
 * Renvoie le mois de l'opération, à jour.
 */
export const recategoriser = (id: number, catégorie: Categorie, autres: number[] = []) =>
  ecriture(api<MoisApi>(`/operations/${id}`, { method: 'PATCH', body: JSON.stringify({ catégorie, autres }) }));

export function previewImport(fichier: File): Promise<ApercuImport> {
  const form = new FormData();
  form.append('fichier', fichier);
  return api<ApercuImport>('/import/preview', { method: 'POST', body: form });
}

export const confirmImport = (lignes: LigneAImporter[]) => ecriture(post<ResultatImport>('/import/confirm', { lignes }));

export const apprendreRegle = (règle: RegleSaisie, appliquer_aux_mois: boolean) =>
  ecriture(post<ResultatApprentissage>('/regles', { règle, appliquer_aux_mois }));

export const correspondances = (règle: RegleSaisie, lignes: { libelle: string; sens: Sens }[]) =>
  post<{ correspondances: boolean[] }>('/regles/correspondances', { règle, lignes });

// --- Catégories ---

export type ActionCategorie =
  | { action: 'ajouter'; sens: Sens; chemin: string[]; icone?: string; sous_catégorie?: string }
  | { action: 'icone'; sens: Sens; chemin: string[]; icone: string }
  | { action: 'renommer'; sens: Sens; chemin: string[]; nom: string }
  | { action: 'supprimer'; sens: Sens; chemin: string[]; remplacement?: string[] };

export interface Usage {
  lignes: number;
  règles: number;
}

export const modifierCategories = (action: ActionCategorie) =>
  ecriture(api<{ catégories: Categories; modifiés: Usage }>('/categories', { method: 'PUT', body: JSON.stringify(action) }));

export const usageCategorie = (sens: Sens, chemin: string[]) => post<Usage>('/categories/usage', { sens, chemin });

// --- Règles ---

export interface ResultatTest {
  type_operation: string;
  marchand: string;
  texte: string;
  catégorie: Categorie;
  source: 'règle' | 'type' | 'aucune';
  règle: Regle | null;
}

export const testerLibelle = (libelle: string, sens: Sens) => post<ResultatTest>('/regles/tester', { libelle, sens });

export const creerRegle = (règle: RegleSaisie) => apprendreRegle(règle, false);

export const modifierRegle = (id: number, règle: RegleSaisie) =>
  api<Regle>(`/regles/${id}`, { method: 'PUT', body: JSON.stringify({ règle }) });

export const supprimerRegle = (id: number) => api<{ supprimée: boolean }>(`/regles/${id}`, { method: 'DELETE' });

// --- Paramètres ---

export interface Parametres {
  thème: Theme;
}

export const getParametres = () => api<Parametres>('/parametres');
export const putParametres = (p: Parametres) => api<Parametres>('/parametres', { method: 'PUT', body: JSON.stringify(p) });
