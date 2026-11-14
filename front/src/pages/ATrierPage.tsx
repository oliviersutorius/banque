import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { totaux, versLignesVue, type LigneVue } from '../aggregate';
import { getATrier, getCategories } from '../api';
import LinesTable from '../components/LinesTable';
import { formatEuros } from '../format';
import type { Categories, MoisATrier } from '../types';
import { useRecategorisation } from '../useRecategorisation';

/** Toutes les lignes encore « À catégoriser », tous mois confondus. */
export default function ATrierPage() {
  const [donnees, setDonnees] = useState<MoisATrier[] | null>(null);
  const [categories, setCategories] = useState<Categories | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(() => getATrier().then(setDonnees, (e: Error) => setErreur(e.message)), []);
  useEffect(() => {
    void charger();
    getCategories().then(setCategories, (e: Error) => setErreur(e.message));
  }, [charger]);

  const lignes: LigneVue[] = useMemo(() => donnees?.flatMap((m) => versLignesVue(m)) ?? [], [donnees]);
  // Une ligne recatégorisée quitte la liste : on recharge plutôt que de fusionner les mois renvoyés
  const recat = useRecategorisation(lignes, 'parmi les lignes à trier', () => void charger());

  if (erreur) {
    return <p className="card error">{erreur}</p>;
  }
  if (!donnees || !categories) {
    return <p className="muted">Chargement…</p>;
  }

  const t = totaux(lignes);
  const depenses = lignes.filter((l) => l.sens === 'dépense');
  const revenus = lignes.filter((l) => l.sens === 'revenu');

  return (
    <div className="stack">
      <h1>🧺 À trier</h1>
      {recat.message && <p className="toast">{recat.message}</p>}
      {recat.erreur && <p className="card error">{recat.erreur}</p>}

      {lignes.length === 0 ? (
        <section className="card empty">
          <p className="empty-icon">✨</p>
          <h2>Tout est rangé !</h2>
          <p className="muted">Aucune ligne « À catégoriser » dans les mois enregistrés.</p>
          <Link to="/mois" className="btn">
            Voir les mois
          </Link>
        </section>
      ) : (
        <>
          <p className="muted">
            {lignes.length} ligne{lignes.length > 1 ? 's' : ''} sur {donnees.length} mois. Clique sur une catégorie pour la corriger : la
            fenêtre d'apprentissage propose de créer une règle et de trier d'un coup les lignes similaires.
          </p>
          {[
            { titre: 'Dépenses', lignes: depenses, total: t.dépenses },
            { titre: 'Revenus', lignes: revenus, total: t.revenus },
          ]
            .filter((g) => g.lignes.length > 0)
            .map((g) => (
              <section key={g.titre} className="card">
                <header className="section-head">
                  <h2>
                    {g.titre} <span className="muted small">({g.lignes.length})</span>
                  </h2>
                  <strong className={g.titre === 'Dépenses' ? 'depense' : 'revenu'}>{formatEuros(g.total)}</strong>
                </header>
                <LinesTable lignes={g.lignes} categories={categories} onRecategoriser={recat.demarrer} />
              </section>
            ))}
        </>
      )}
      {recat.dialogue}
    </div>
  );
}
