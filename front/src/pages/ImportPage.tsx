import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { confirmImport, getCategories, previewImport } from '../api';
import { categorie1, estACategoriser, icone, memeCategorie } from '../categories';
import CategorySelect from '../components/CategorySelect';
import LearnDialog from '../components/LearnDialog';
import { formatEuros, toCents } from '../format';
import { lignesAEnvoyer, ordonnerLignes, totauxNouvelles } from '../importLogic';
import { libelleMois } from '../months';
import type { ApercuImport, Categorie, Categories, LigneApercu, ResultatImport } from '../types';

interface Correction {
  ligne: LigneApercu;
  catégorie: Categorie;
}

export default function ImportPage() {
  const [categories, setCategories] = useState<Categories | null>(null);
  const [fichier, setFichier] = useState<string | null>(null);
  const [apercu, setApercu] = useState<ApercuImport | null>(null);
  const [corrections, setCorrections] = useState<Map<number, Categorie>>(new Map());
  const [enCours, setEnCours] = useState<Correction | null>(null);
  const [resultat, setResultat] = useState<ResultatImport | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);
  const [survol, setSurvol] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    getCategories().then(setCategories, (e: Error) => setErreur(e.message));
  }, []);

  const toutesLignes = useMemo(() => apercu?.mois.flatMap((m) => m.lignes) ?? [], [apercu]);
  const categorieDe = (l: LigneApercu) => corrections.get(l.id) ?? l.catégorie;
  const restantes = toutesLignes.filter((l) => !l.doublon && estACategoriser(categorieDe(l))).length;

  async function analyser(file: File) {
    setErreur(null);
    setMessage(null);
    setResultat(null);
    setApercu(null);
    setCorrections(new Map());
    setChargement(true);
    try {
      setApercu(await previewImport(file));
      setFichier(file.name);
    } catch (e) {
      setErreur((e as Error).message);
    } finally {
      setChargement(false);
    }
  }

  function corriger(ligne: LigneApercu, catégorie: Categorie) {
    if (memeCategorie(catégorie, categorieDe(ligne))) {
      return;
    }
    setCorrections((c) => new Map(c).set(ligne.id, catégorie));
    setEnCours({ ligne, catégorie });
  }

  // Lignes candidates aux « lignes similaires » : nouvelles, même sens, pas encore corrigées à la main
  const candidates = enCours
    ? toutesLignes.filter((l) => !l.doublon && l.sens === enCours.ligne.sens && l.id !== enCours.ligne.id && !corrections.has(l.id))
    : [];

  function finApprentissage(r: { similaires: number[]; message?: string }) {
    if (enCours) {
      const ids = r.similaires.map((i) => candidates[i]?.id).filter((id): id is number => id !== undefined);
      setCorrections((c) => {
        const next = new Map(c);
        ids.forEach((id) => next.set(id, enCours.catégorie));
        return next;
      });
      const parts = [r.message, ids.length > 0 ? `${ids.length} ligne(s) similaire(s) mise(s) à jour` : undefined];
      setMessage(parts.filter(Boolean).join(' · ') || null);
    }
    setEnCours(null);
  }

  async function confirmer() {
    if (!apercu) {
      return;
    }
    setChargement(true);
    setErreur(null);
    try {
      setResultat(await confirmImport(lignesAEnvoyer(apercu, corrections)));
      setApercu(null);
      setMessage(null);
    } catch (e) {
      setErreur((e as Error).message);
    } finally {
      setChargement(false);
    }
  }

  return (
    <div className="stack">
      <h1>📥 Import d'un relevé</h1>

      <section
        className={'card dropzone' + (survol ? ' over' : '')}
        onDragOver={(e) => {
          e.preventDefault();
          setSurvol(true);
        }}
        onDragLeave={() => setSurvol(false)}
        onDrop={(e) => {
          e.preventDefault();
          setSurvol(false);
          const file = e.dataTransfer.files[0];
          if (file) void analyser(file);
        }}
      >
        <p className="dropzone-icon">🐣</p>
        <p>Dépose ton fichier CSV du Crédit Agricole ici</p>
        <button type="button" className="btn" onClick={() => input.current?.click()} disabled={chargement}>
          Choisir un fichier
        </button>
        <input
          ref={input}
          type="file"
          accept=".csv,text/csv"
          hidden
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void analyser(file);
            e.target.value = '';
          }}
        />
        {chargement && <p className="muted">Un instant…</p>}
      </section>

      {erreur && <p className="card error">{erreur}</p>}
      {message && <p className="toast">{message}</p>}

      {resultat && (
        <section className="card">
          <h2>🎉 Import terminé</h2>
          <p>
            {resultat.ajoutées} ligne(s) ajoutée(s).
          </p>
          <ul className="plain">
            {resultat.mois.map((m) => (
              <li key={m.mois}>
                <Link to={`/mois/${m.mois}`}>{libelleMois(m.mois)}</Link> : {m.ajoutées} ajoutée(s)
                {m.doublons > 0 && <span className="muted">, {m.doublons} doublon(s) ignoré(s)</span>}
              </li>
            ))}
          </ul>
        </section>
      )}

      {apercu && categories && (
        <>
          <section className="summary">
            <div className="card stat">
              <span className="muted">Fichier</span>
              <strong>{fichier}</strong>
              <span className="muted small">{apercu.résumé.lignes} lignes · {apercu.résumé.mois.length} mois</span>
            </div>
            <div className="card stat">
              <span className="muted">Nouvelles</span>
              <strong>{apercu.résumé.nouvelles}</strong>
              <span className="muted small">{apercu.résumé.doublons} doublon(s) ignoré(s)</span>
            </div>
            <div className="card stat">
              <span className="muted">Dépenses</span>
              <strong className="depense">{formatEuros(toCents(apercu.résumé.dépenses))}</strong>
            </div>
            <div className="card stat">
              <span className="muted">Revenus</span>
              <strong className="revenu">{formatEuros(toCents(apercu.résumé.revenus))}</strong>
            </div>
            <div className={'card stat' + (restantes > 0 ? ' attention' : '')}>
              <span className="muted">À catégoriser</span>
              <strong>{restantes}</strong>
            </div>
          </section>

          {apercu.erreurs.length > 0 && (
            <section className="card">
              <h2>⚠️ {apercu.erreurs.length} ligne(s) non lue(s)</h2>
              <p className="muted small">Ces lignes ne seront pas importées.</p>
              <table className="table">
                <thead>
                  <tr>
                    <th>Ligne</th>
                    <th>Raison</th>
                    <th>Contenu</th>
                  </tr>
                </thead>
                <tbody>
                  {apercu.erreurs.map((e) => (
                    <tr key={e.ligne}>
                      <td className="num">{e.ligne}</td>
                      <td>{e.raison}</td>
                      <td className="mono small">{e.contenu}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}

          {apercu.mois.map((m) => {
            const totaux = totauxNouvelles(m.lignes);
            const nouvelles = m.lignes.filter((l) => !l.doublon).length;
            return (
              <section key={m.mois} className="card">
                <header className="section-head">
                  <h2>{libelleMois(m.mois)}</h2>
                  <span className="muted small">
                    {m.existant ? 'mois déjà enregistré · ' : ''}
                    {nouvelles} nouvelle(s) · {m.lignes.length - nouvelles} doublon(s)
                    {' · '}
                    <span className="depense">−{formatEuros(totaux.dépenses)}</span>
                    {' · '}
                    <span className="revenu">+{formatEuros(totaux.revenus)}</span>
                  </span>
                </header>
                <div className="table-wrap">
                  <table className="table">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Libellé</th>
                        <th>Catégorie</th>
                        <th className="num">Montant</th>
                      </tr>
                    </thead>
                    <tbody>
                      {ordonnerLignes(m.lignes).map((l) => {
                        const cat = categorieDe(l);
                        const aRevoir = !l.doublon && (estACategoriser(cat) || (l.à_revoir && !corrections.has(l.id)));
                        return (
                          <tr key={l.id} className={l.doublon ? 'duplicate' : aRevoir ? 'review' : ''}>
                            <td className="nowrap">{l.date}</td>
                            <td>
                              {l.libelle}
                              {l.doublon && <span className="badge">doublon</span>}
                              {!l.doublon && l.à_revoir && !estACategoriser(l.catégorie) && !corrections.has(l.id) && (
                                <span className="badge">à confirmer</span>
                              )}
                            </td>
                            <td>
                              <span className="cat-icon" aria-hidden="true">
                                {icone(categories, l.sens, categorie1(cat))}
                              </span>
                              <CategorySelect
                                categories={categories}
                                sens={l.sens}
                                value={cat}
                                disabled={l.doublon}
                                onChange={(c) => corriger(l, c)}
                              />
                            </td>
                            <td className={'num nowrap ' + (l.sens === 'dépense' ? 'depense' : 'revenu')}>
                              {l.sens === 'dépense' ? '−' : '+'}
                              {formatEuros(toCents(l.montant))}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </section>
            );
          })}

          <div className="actions-bar">
            <button type="button" className="btn ghost" onClick={() => setApercu(null)} disabled={chargement}>
              Annuler
            </button>
            <button type="button" className="btn" onClick={confirmer} disabled={chargement || apercu.résumé.nouvelles === 0}>
              {apercu.résumé.nouvelles === 0 ? 'Rien de nouveau' : `Importer ${apercu.résumé.nouvelles} ligne(s)`}
            </button>
          </div>
        </>
      )}

      {enCours && (
        <LearnDialog
          ligne={{ ...enCours.ligne, catégorie: enCours.catégorie }}
          lot={candidates.map((l) => ({ libelle: l.libelle, sens: l.sens }))}
          proposerMois
          onClose={finApprentissage}
        />
      )}
    </div>
  );
}
