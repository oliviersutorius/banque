import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';
import { matrice, parCategorie1, totaux, versLignesVue, type LigneVue } from '../aggregate';
import { getAnnee, getCategories, getMoisDisponibles } from '../api';
import CategoryBlock, { type Affichage } from '../components/CategoryBlock';
import CategoryDetail from '../components/CategoryDetail';
import SummaryCards from '../components/SummaryCards';
import YearMatrix from '../components/YearMatrix';
import type { AnneeApi, Categories, Sens } from '../types';
import { useRecategorisation } from '../useRecategorisation';

export default function YearPage() {
  const { annee } = useParams();
  const navigate = useNavigate();
  const [disponibles, setDisponibles] = useState<string[] | null>(null);
  const [categories, setCategories] = useState<Categories | null>(null);
  const [donnees, setDonnees] = useState<AnneeApi | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [affichage, setAffichage] = useState<Record<Sens, Affichage>>({ dépense: 'batons', revenu: 'batons' });
  const [selection, setSelection] = useState<{ sens: Sens; cat1: string } | null>(null);

  useEffect(() => {
    Promise.all([getMoisDisponibles(), getCategories()]).then(([m, c]) => {
      setDisponibles(m);
      setCategories(c);
    }, (e: Error) => setErreur(e.message));
  }, []);

  const annees = useMemo(() => [...new Set((disponibles ?? []).map((m) => m.slice(0, 4)))].sort(), [disponibles]);

  useEffect(() => {
    if (!annee || !annees.includes(annee)) {
      return;
    }
    setDonnees(null);
    setSelection(null);
    getAnnee(annee).then(setDonnees, (e: Error) => setErreur(e.message));
  }, [annee, annees]);

  const lignes: LigneVue[] = useMemo(() => donnees?.mois.flatMap((m) => versLignesVue(m)) ?? [], [donnees]);
  const enregistres = useMemo(() => new Set(donnees?.mois.map((m) => m.mois)), [donnees]);
  const recat = useRecategorisation(lignes, 'de cette année', (resultats) =>
    setDonnees((d) => d && { ...d, mois: d.mois.map((m) => resultats.find((r) => r.mois === m.mois) ?? m) }),
  );

  if (erreur) {
    return <p className="card error">{erreur}</p>;
  }
  if (!disponibles || !categories) {
    return <p className="muted">Chargement…</p>;
  }
  if (annees.length === 0) {
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
  if (!annee || !annees.includes(annee)) {
    return <Navigate to={`/annee/${annees[annees.length - 1]}`} replace />;
  }

  const position = annees.indexOf(annee);
  const precedente = annees[position - 1];
  const suivante = annees[position + 1];
  const t = totaux(lignes);
  const tableauOuvert = affichage.dépense === 'tableau' || affichage.revenu === 'tableau';

  const bloc = (sens: Sens, titre: string) => {
    const select = (cat1: string) => setSelection((s) => (s?.sens === sens && s.cat1 === cat1 ? null : { sens, cat1 }));
    return (
      <CategoryBlock
        titre={titre}
        sens={sens}
        parts={parCategorie1(lignes, sens)}
        categories={categories}
        affichage={affichage[sens]}
        onAffichage={(a) => setAffichage((v) => ({ ...v, [sens]: a }))}
        onSelect={select}
        selection={selection?.sens === sens ? selection.cat1 : null}
        tableau={
          <YearMatrix annee={annee} sens={sens} matrice={matrice(lignes, sens, annee, enregistres)} categories={categories} onSelect={select} />
        }
      />
    );
  };

  return (
    <div className="stack">
      <header className="period-bar">
        <button type="button" className="btn ghost round" disabled={!precedente} onClick={() => navigate(`/annee/${precedente}`)} aria-label="Année précédente">
          ‹
        </button>
        <select className="period-select" value={annee} onChange={(e) => navigate(`/annee/${e.target.value}`)}>
          {[...annees].reverse().map((a) => (
            <option key={a} value={a}>
              {a}
            </option>
          ))}
        </select>
        <button type="button" className="btn ghost round" disabled={!suivante} onClick={() => navigate(`/annee/${suivante}`)} aria-label="Année suivante">
          ›
        </button>
      </header>
      {donnees && (
        <p className="muted small center">
          {donnees.mois.length} mois importé(s) sur 12
        </p>
      )}

      {recat.message && <p className="toast">{recat.message}</p>}
      {recat.erreur && <p className="card error">{recat.erreur}</p>}

      {!donnees ? (
        <p className="muted">Chargement…</p>
      ) : (
        <>
          <SummaryCards {...t} periode="cette année" />
          {/* La matrice a 14 colonnes : quand un tableau est ouvert, les blocs passent l'un sous l'autre */}
          <div className={'blocks' + (tableauOuvert ? ' wide' : '')}>
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
