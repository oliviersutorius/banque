import { useState, type ReactNode } from 'react';
import type { LigneVue } from './aggregate';
import { recategoriser } from './api';
import { memeCategorie } from './categories';
import LearnDialog from './components/LearnDialog';
import type { Categorie, MoisApi } from './types';

interface EnCours {
  ligne: LigneVue;
  catégorie: Categorie;
  /** Lignes candidates aux « lignes similaires » */
  lot: LigneVue[];
}

/**
 * Recatégorisation d'une ligne enregistrée avec l'apprentissage du §4 :
 * la fenêtre propose une règle, puis la ligne et les lignes similaires choisies sont enregistrées
 * (un appel par mois concerné, qui renvoie ce mois à jour).
 */
export function useRecategorisation(lignes: LigneVue[], contexteLot: string, onMisAJour: (mois: MoisApi[]) => void) {
  const [enCours, setEnCours] = useState<EnCours | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  function demarrer(ligne: LigneVue, catégorie: Categorie) {
    if (memeCategorie(ligne.catégorie, catégorie)) {
      return;
    }
    setMessage(null);
    setErreur(null);
    const lot = lignes.filter(
      (l) => l.sens === ligne.sens && l.id !== ligne.id && !memeCategorie(l.catégorie, catégorie),
    );
    setEnCours({ ligne, catégorie, lot });
  }

  async function terminer({ similaires, message: info }: { similaires: number[]; message?: string }) {
    if (!enCours) {
      return;
    }
    const { ligne, catégorie, lot } = enCours;
    setEnCours(null);

    const parMois = new Map<string, LigneVue[]>([[ligne.mois, [ligne]]]);
    for (const i of similaires) {
      const l = lot[i];
      if (l) parMois.set(l.mois, [...(parMois.get(l.mois) ?? []), l]);
    }
    try {
      const resultats: MoisApi[] = [];
      for (const [premiere, ...autres] of parMois.values()) {
        if (premiere) resultats.push(await recategoriser(premiere.id, catégorie, autres.map((l) => l.id)));
      }
      onMisAJour(resultats);
      const total = [...parMois.values()].reduce((s, l) => s + l.length, 0);
      setMessage([info, total > 1 ? `${total} lignes recatégorisées` : 'Ligne recatégorisée'].filter(Boolean).join(' · '));
    } catch (e) {
      setErreur((e as Error).message);
    }
  }

  const dialogue: ReactNode = enCours && (
    <LearnDialog
      ligne={{ ...enCours.ligne, catégorie: enCours.catégorie }}
      lot={enCours.lot.map((l) => ({ libelle: l.libelle, sens: l.sens }))}
      contexteLot={contexteLot}
      proposerMois
      onClose={terminer}
    />
  );

  return { demarrer, dialogue, message, erreur };
}
