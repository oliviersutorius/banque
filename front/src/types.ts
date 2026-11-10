/** Types partagés : format des fichiers JSON de data/ et réponses de l'API. */

export type Sens = 'dépense' | 'revenu';

export interface CategorieDepense {
  catégorie1: string;
  catégorie2: string;
}

/** Une dépense a une catégorie à 2 niveaux, un revenu une catégorie à 1 niveau. */
export type Categorie = CategorieDepense | string;

interface LigneBase {
  date: string;
  libelle: string;
}

export type LigneMois =
  | (LigneBase & { catégorie: CategorieDepense; dépense: string })
  | (LigneBase & { catégorie: string; revenu: string });

/** data/mois/YYYYMM.json */
export interface FichierMois {
  mois: string;
  lignes: LigneMois[];
}

/** data/categories.json */
export interface Categories {
  dépenses: { nom: string; icone: string; sous_catégories: string[] }[];
  revenus: { nom: string; icone: string }[];
}

/** Un élément de data/regles.json */
export interface Regle {
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

export type RegleSaisie = Omit<Regle, 'origine' | 'a_confirmer'>;

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
