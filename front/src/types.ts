/** Types partagés : réponses de l'API (les données sont en base SQLite côté serveur). */

export type Sens = 'dépense' | 'revenu';

export type Theme = 'guimauve' | 'sauge' | 'nuit';

export interface CategorieDepense {
  catégorie1: string;
  catégorie2: string;
}

/** Une dépense a une catégorie à 2 niveaux, un revenu une catégorie à 1 niveau. */
export type Categorie = CategorieDepense | string;

interface LigneBase {
  /** Identifiant de l'opération en base */
  id: number;
  date: string;
  libelle: string;
}

export type LigneMois =
  | (LigneBase & { catégorie: CategorieDepense; dépense: string })
  | (LigneBase & { catégorie: string; revenu: string });

/** Les opérations d'un mois, triées par date puis ordre d'arrivée */
export interface FichierMois {
  mois: string;
  lignes: LigneMois[];
}

/** Réponse de GET /api/mois/{YYYYMM} : les lignes du mois et, pour chaque ligne, son libellé analysé */
export interface MoisApi extends FichierMois {
  analyse: { type_operation: string; marchand: string }[];
}

/** Un élément de GET /api/a-trier : les lignes « À catégoriser » d'un mois */
export type MoisATrier = MoisApi;

/** Réponse de GET /api/annee/{YYYY} : les mois enregistrés de l'année */
export interface AnneeApi {
  année: string;
  mois: MoisApi[];
}

/** Réponse de GET /api/categories */
export interface Categories {
  dépenses: { nom: string; icone: string; sous_catégories: string[] }[];
  revenus: { nom: string; icone: string }[];
}

/** Un élément de GET /api/regles */
export interface Regle {
  /** Identifiant en base (absent pour une règle pas encore enregistrée) */
  id?: number;
  motif: string;
  type: Sens;
  catégorie1?: string;
  catégorie2?: string;
  catégorie?: string;
  et?: string[];
  type_operation?: string;
  a_confirmer?: boolean;
  origine: 'initiale' | 'apprise';
}

export type RegleSaisie = Omit<Regle, 'origine' | 'id'>;

/** Réponse de GET /api/regles/mutualisation */
export interface Mutualisation {
  règles: number;
  /** Règles qu'une règle plus générale de même catégorie couvre déjà : suppression sans effet sur les opérations */
  doublons: { clé: string; règle: Regle; couverte_par: Regle }[];
  /** Règles qui commencent par les mêmes mots, remplaçables par une règle sur ces mots */
  regroupements: {
    clé: string;
    motif: string;
    règles: Regle[];
    nouvelle: Regle;
    /** Nombre d'opérations dont la catégorie changerait : non applicable si > 0 */
    impact: number;
    exemples: { libelle: string; avant: string; après: string }[];
  }[];
}

/** Réponse de POST /api/regles/mutualisation */
export interface ResultatMutualisation {
  supprimées: number;
  créées: number;
  /** Nom du fichier de sauvegarde fait avant les changements */
  sauvegarde: string | null;
}

// --- Import ---

export interface LigneApercu {
  id: number;
  ligne: number;
  date: string;
  libelle: string;
  sens: Sens;
  montant: string;
  catégorie: Categorie;
  type_operation: string;
  marchand: string;
  source: 'règle' | 'type' | 'aucune';
  motif: string | null;
  à_revoir: boolean;
  doublon: boolean;
}

export interface ApercuImport {
  résumé: {
    lignes: number;
    mois: string[];
    dépenses: string;
    revenus: string;
    erreurs: number;
    nouvelles: number;
    doublons: number;
    à_revoir: number;
  };
  mois: { mois: string; existant: boolean; lignes: LigneApercu[] }[];
  erreurs: { ligne: number; contenu: string; raison: string }[];
}

export interface LigneAImporter {
  date: string;
  libelle: string;
  sens: Sens;
  montant: string;
  catégorie: Categorie;
}

export interface ResultatImport {
  ajoutées: number;
  mois: { mois: string; ajoutées: number; doublons: number }[];
}

export interface ResultatApprentissage {
  règle: Regle;
  remplacée: boolean;
  lignes_mises_à_jour: number;
}
