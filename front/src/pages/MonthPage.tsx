import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';
import { parCategorie1, totaux, versLignesVue, type LigneVue } from '../aggregate';
import { getCategories, getMois, getMoisDisponibles } from '../api';
import CategoryBlock, { type Affichage } from '../components/CategoryBlock';
import CategoryDetail from '../components/CategoryDetail';
import LinesTable from '../components/LinesTable';
import SummaryCards from '../components/SummaryCards';
import { libelleMois } from '../months';
import type { Categories, MoisApi, Sens } from '../types';
import { useRecategorisation } from '../useRecategorisation';

export default function MonthPage() {
  const { mois } = useParams();
  const navigate = useNavigate();
  const [disponibles, setDisponibles] = useState<string[] | null>(null);
  const [categories, setCategories] = useState<Categories | null>(null);
  const [donnees, setDonnees] = useState<MoisApi | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [affichage, setAffichage] = useState<Record<Sens, Affichage>>({ dépense: 'batons', revenu: 'batons' });
  const [selection, setSelection] = useState<{ sens: Sens; cat1: string } | null>(null);

  useEffect(() => {
    Promise.all([getMoisDisponibles(), getCategories()]).then(([m, c]) => {
      setDisponibles(m);
      setCategories(c);
    }, (e: Error) => setErreur(e.message));
  }, []);

  useEffect(() => {
    if (!mois || !disponibles?.includes(mois)) {
      return;
    }
    setDonnees(null);
    setSelection(null);
    getMois(mois).then(setDonnees, (e: Error) => setErreur(e.message));
  }, [mois, disponibles]);

  const lignes: LigneVue[] = useMemo(() => (donnees ? versLignesVue(donnees) : []), [donnees]);
  const recat = useRecategorisation(lignes, 'de ce mois', (resultats) => {
    const courant = resultats.find((r) => r.mois === donnees?.mois);
    if (courant) setDonnees(courant);
  });

  if (erreur) {
    return <p className="card error">{erreur}</p>;
  }
  if (!disponibles || !categories) {
    return <p className="muted">Chargement…</p>;
  }
  if (disponibles.length === 0) {
    return (
      <section className="card empty">
        <p className="empty-icon">🐣</p>
        <h1>Aucun mois importé pour l'instant</h1>
        <p className="muted">Dépose ton premier CSV pour commencer.</p>
        <Link to="/import" className="btn">
          Importer un relevé
        </Link>
      </section>
    );
  }
  if (!mois || !disponibles.includes(mois)) {
    return <Navigate to={`/mois/${disponibles[disponibles.length - 1]}`} replace />;
  }

  const position = disponibles.indexOf(mois);
  const precedent = disponibles[position - 1];
  const suivant = disponibles[position + 1];
  const t = totaux(lignes);

  const bloc = (sens: Sens, titre: string) => {
    const parts = parCategorie1(lignes, sens);
    const select = (cat1: string) =>
      setSelection((s) => (s?.sens === sens && s.cat1 === cat1 ? null : { sens, cat1 }));
    return (
      <CategoryBlock
        titre={titre}
        sens={sens}
        parts={parts}
        categories={categories}
        affichage={affichage[sens]}
        onAffichage={(a) => setAffichage((v) => ({ ...v, [sens]: a }))}
        onSelect={select}
        selection={selection?.sens === sens ? selection.cat1 : null}
        tableau={
          <LinesTable
            lignes={lignes.filter((l) => l.sens === sens)}
            categories={categories}
            onRecategoriser={recat.demarrer}
            sousTotaux={parts}
            onSelect={select}
          />
        }
      />
    );
  };

  return (
    <div className="stack">
      <header className="period-bar">
        <button type="button" className="btn ghost round" disabled={!precedent} onClick={() => navigate(`/mois/${precedent}`)} aria-label="Mois précédent">
          ‹
        </button>
        <select className="period-select" value={mois} onChange={(e) => navigate(`/mois/${e.target.value}`)}>
          {[...disponibles].reverse().map((m) => (
            <option key={m} value={m}>
              {libelleMois(m)}
            </option>
          ))}
        </select>
        <button type="button" className="btn ghost round" disabled={!suivant} onClick={() => navigate(`/mois/${suivant}`)} aria-label="Mois suivant">
          ›
        </button>
      </header>

      {recat.message && <p className="toast">{recat.message}</p>}
      {recat.erreur && <p className="card error">{recat.erreur}</p>}

      {!donnees ? (
        <p className="muted">Chargement…</p>
      ) : (
        <>
          <SummaryCards {...t} periode="ce mois-ci" />
          <div className="blocks">
            {bloc('dépense', 'Dépenses par catégorie')}
            {bloc('revenu', 'Revenus par catégorie')}
          </div>
          {selection && (
            <CategoryDetail
              key={selection.sens + selection.cat1}
              cat1={selection.cat1}
              sens={selection.sens}
              lignes={lignes}
              categories={categories}
              onRecategoriser={recat.demarrer}
              onClose={() => setSelection(null)}
            />
          )}
        </>
      )}
      {recat.dialogue}
    </div>
  );
}
