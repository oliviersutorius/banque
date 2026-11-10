import { useEffect, useRef, useState } from 'react';
import { apprendreRegle, correspondances } from '../api';
import { libelleCategorie, regle } from '../categories';
import type { Categorie, Sens } from '../types';

export interface LigneCorrigee {
  libelle: string;
  sens: Sens;
  marchand: string;
  type_operation: string;
  catégorie: Categorie;
}

interface Props {
  ligne: LigneCorrigee;
  /** Lignes du lot en cours parmi lesquelles chercher les lignes similaires */
  lot: { libelle: string; sens: Sens }[];
  /** Désignation du lot dans la fenêtre : « de cet import », « de ce mois »… */
  contexteLot: string;
  /** Propose d'appliquer la règle aux mois déjà enregistrés encore « À catégoriser » */
  proposerMois: boolean;
  onClose: (resultat: { similaires: number[]; message?: string }) => void;
}

const LIBELLES_TYPES: Record<string, string> = {
  CARTE: 'paiements par carte',
  PRELEVEMENT: 'prélèvements',
  VIREMENT_RECU: 'virements reçus',
  VIREMENT_EMIS: 'virements émis',
  RETRAIT: 'retraits',
  REGLEMENT: 'règlements',
  COTISATION: 'cotisations',
};

/**
 * Apprentissage après une correction : créer une règle et l'appliquer aux lignes similaires.
 */
export default function LearnDialog({ ligne, lot, contexteLot, proposerMois, onClose }: Props) {
  const [motif, setMotif] = useState(ligne.marchand);
  const [creerRegle, setCreerRegle] = useState(true);
  const [limiterType, setLimiterType] = useState(false);
  const [appliquerLot, setAppliquerLot] = useState(true);
  const [appliquerMois, setAppliquerMois] = useState(false);
  const [similaires, setSimilaires] = useState<number[]>([]);
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const dialog = useRef<HTMLDialogElement>(null);

  const motifValide = motif.trim() !== '';
  const typeLisible = LIBELLES_TYPES[ligne.type_operation];
  const saisie = regle(motif.trim(), ligne.sens, ligne.catégorie, limiterType ? ligne.type_operation : undefined);

  useEffect(() => {
    dialog.current?.showModal();
  }, []);

  // Recherche des lignes similaires du lot à chaque modification du motif (avec un court délai)
  useEffect(() => {
    if (!motifValide) {
      setSimilaires([]);
      return;
    }
    const timer = setTimeout(() => {
      correspondances(saisie, lot).then(
        (r) => {
          setSimilaires(r.correspondances.flatMap((ok, i) => (ok ? [i] : [])));
          setErreur(null);
        },
        (e: Error) => setErreur(e.message),
      );
    }, 250);
    return () => clearTimeout(timer);
    // saisie est recalculée à chaque rendu : on dépend seulement de ce qui la compose
  }, [motif, limiterType]);

  async function valider() {
    setEnvoi(true);
    try {
      let message: string | undefined;
      if (creerRegle) {
        const r = await apprendreRegle(saisie, proposerMois && appliquerMois);
        message = `Règle « ${r.règle.motif} » ${r.remplacée ? 'mise à jour' : 'créée'}`;
        if (proposerMois && appliquerMois) {
          message += `, ${r.lignes_mises_à_jour} ligne(s) déjà enregistrée(s) mise(s) à jour`;
        }
      }
      onClose({ similaires: appliquerLot && motifValide ? similaires : [], message });
    } catch (e) {
      setErreur((e as Error).message);
      setEnvoi(false);
    }
  }

  return (
    <dialog ref={dialog} className="dialog" onCancel={() => onClose({ similaires: [] })}>
      <h2>✨ Retenir cette correction ?</h2>
      <p className="muted small">{ligne.libelle}</p>
      <p>
        Nouvelle catégorie : <strong>{libelleCategorie(ligne.catégorie)}</strong>
      </p>

      <label className="field">
        <span>Motif reconnu dans le libellé</span>
        <input value={motif} onChange={(e) => setMotif(e.target.value)} autoFocus />
        <small className="muted">Raccourcis-le pour qu'il couvre plus de lignes (ex. « AUCHAN PEROLS » → « AUCHAN »).</small>
      </label>

      <label className="check">
        <input type="checkbox" checked={creerRegle} onChange={(e) => setCreerRegle(e.target.checked)} />
        Créer une règle pour les prochains imports
      </label>
      {typeLisible && (
        <label className="check">
          <input type="checkbox" checked={limiterType} onChange={(e) => setLimiterType(e.target.checked)} />
          Seulement pour les {typeLisible}
        </label>
      )}
      <label className="check">
        <input type="checkbox" checked={appliquerLot} onChange={(e) => setAppliquerLot(e.target.checked)} />
        Appliquer aux lignes similaires {contexteLot} ({similaires.length})
      </label>
      {proposerMois && (
        <label className="check">
          <input
            type="checkbox"
            checked={creerRegle && appliquerMois}
            disabled={!creerRegle}
            onChange={(e) => setAppliquerMois(e.target.checked)}
          />
          Aussi aux mois déjà enregistrés, pour les lignes encore « À catégoriser »
        </label>
      )}

      {erreur && <p className="error">{erreur}</p>}

      <div className="dialog-actions">
        <button type="button" className="btn ghost" onClick={() => onClose({ similaires: [] })} disabled={envoi}>
          Juste cette ligne
        </button>
        <button type="button" className="btn" onClick={valider} disabled={envoi || (!motifValide && (creerRegle || appliquerLot))}>
          Valider
        </button>
      </div>
    </dialog>
  );
}
