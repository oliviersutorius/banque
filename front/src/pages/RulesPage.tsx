import { useEffect, useMemo, useState } from 'react';
import {
  appliquerMutualisation,
  creerRegle,
  getCategories,
  getMutualisation,
  getRegles,
  modifierRegle,
  supprimerRegle,
  testerLibelle,
  type ResultatTest,
} from '../api';
import { A_CATEGORISER, libelleCategorie, regle as construireRegle } from '../categories';
import CategoryPicker from '../components/CategoryPicker';
import CategorySelect from '../components/CategorySelect';
import Modal from '../components/Modal';
import Pagination from '../components/Pagination';
import Spinner from '../components/Spinner';
import { TYPES_OPERATION } from '../operationTypes';
import { paginer } from '../pagination';
import type { Categorie, Categories, Mutualisation, Regle, Sens } from '../types';

type Filtre = 'toutes' | 'apprise' | 'initiale';

const REGLES_PAR_PAGE = 25;

const categorieDe = (r: Regle): Categorie =>
  r.type === 'dépense' ? { catégorie1: r.catégorie1 ?? '', catégorie2: r.catégorie2 ?? '' } : (r.catégorie ?? '');

export default function RulesPage() {
  const [regles, setRegles] = useState<Regle[] | null>(null);
  const [categories, setCategories] = useState<Categories | null>(null);
  const [filtre, setFiltre] = useState<Filtre>('toutes');
  const [recherche, setRecherche] = useState('');
  const [page, setPage] = useState(1);
  const [edition, setEdition] = useState<{ id: number | null; regle: Regle } | null>(null);
  const [suppression, setSuppression] = useState<number | null>(null);
  const [mutualisation, setMutualisation] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = () => getRegles().then(setRegles, (e: Error) => setErreur(e.message));
  useEffect(() => {
    void charger();
    getCategories().then(setCategories, (e: Error) => setErreur(e.message));
  }, []);

  const visibles = useMemo(() => {
    const texte = recherche.trim().toUpperCase();
    return (regles ?? [])
      .map((r, position) => ({ r, id: r.id ?? -1 - position }))
      .filter(({ r }) => filtre === 'toutes' || r.origine === filtre)
      .filter(({ r }) => texte === '' || r.motif.toUpperCase().includes(texte) || libelleCategorie(categorieDe(r)).toUpperCase().includes(texte));
  }, [regles, filtre, recherche]);
  const pagination = paginer(visibles, page, REGLES_PAR_PAGE);

  if (!regles || !categories) {
    return erreur ? <p className="card error">{erreur}</p> : <p className="muted">Chargement…</p>;
  }

  const nouvelle: Regle = {
    motif: '',
    type: 'dépense',
    catégorie1: A_CATEGORISER,
    catégorie2: 'Non classé',
    origine: 'apprise',
  };

  async function supprimer(id: number) {
    const r = regles?.find((regle) => regle.id === id);
    if (!r) return;
    try {
      await supprimerRegle(id);
      setMessage(`Règle « ${r.motif} » supprimée`);
    } catch (e) {
      setErreur((e as Error).message);
    }
    setSuppression(null);
    await charger();
  }

  return (
    <div className="stack">
      <h1>🧩 Règles</h1>
      {message && <p className="toast">{message}</p>}
      {erreur && <p className="card error">{erreur}</p>}

      <Testeur categories={categories} />

      <section className="card">
        <header className="section-head">
          <h2>{regles.length} règles</h2>
          <div className="head-buttons">
            <button
              type="button"
              className="btn ghost"
              title="Repérer les règles en double et celles qui peuvent être regroupées"
              onClick={() => setMutualisation(true)}
            >
              🧹 Mutualiser
            </button>
            <button type="button" className="btn" onClick={() => setEdition({ id: null, regle: nouvelle })}>
              + Règle
            </button>
          </div>
        </header>
        <div className="filters">
          <input className="search" placeholder="Rechercher un motif ou une catégorie" value={recherche} onChange={(e) => {
              setRecherche(e.target.value);
              setPage(1);
            }} />
          <div className="switch" role="tablist">
            {(['toutes', 'apprise', 'initiale'] as const).map((f) => (
              <button key={f} type="button" role="tab" aria-selected={filtre === f} className={filtre === f ? 'active' : ''} onClick={() => {
                  setFiltre(f);
                  setPage(1);
                }}>
                {f === 'toutes' ? 'Toutes' : f === 'apprise' ? 'Apprises' : 'Initiales'}
              </button>
            ))}
          </div>
        </div>
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Motif</th>
                <th>Sens</th>
                <th>Catégorie</th>
                <th>Conditions</th>
                <th>Origine</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {pagination.elements.map(({ r, id }) => (
                <tr key={id}>
                  <td className="mono-soft">
                    {r.motif}
                    {r.et?.map((m) => (
                      <span key={m} className="muted"> + {m}</span>
                    ))}
                  </td>
                  <td>{r.type}</td>
                  <td>{libelleCategorie(categorieDe(r))}</td>
                  <td className="small">
                    {r.type_operation && <span className="badge">{TYPES_OPERATION[r.type_operation] ?? r.type_operation}</span>}
                    {r.a_confirmer && <span className="badge">à confirmer</span>}
                  </td>
                  <td>
                    <span className={'badge ' + r.origine}>{r.origine}</span>
                  </td>
                  <td className="nowrap">
                    <button type="button" className="icon-btn" title="Modifier" onClick={() => setEdition({ id, regle: r })}>
                      ✏️
                    </button>
                    <button type="button" className="icon-btn" title="Supprimer" onClick={() => setSuppression(id)}>
                      🗑️
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {visibles.length > 0 && (
          <p className="muted small">
            Règles {pagination.debut} à {pagination.fin} sur {visibles.length}
          </p>
        )}
        <Pagination page={pagination.page} nbPages={pagination.nbPages} onChange={setPage} />
      </section>

      {edition && (
        <RegleDialog
          id={edition.id}
          initiale={edition.regle}
          categories={categories}
          onFermer={() => setEdition(null)}
          onEnregistre={async (texte) => {
            setEdition(null);
            setMessage(texte);
            await charger();
          }}
        />
      )}
      {mutualisation && (
        <MutualisationDialog
          onFermer={() => setMutualisation(false)}
          onApplique={async (texte) => {
            setMutualisation(false);
            setMessage(texte);
            await charger();
          }}
        />
      )}
      {suppression !== null && (
        <Modal titre={`Supprimer la règle « ${regles.find((r) => r.id === suppression)?.motif ?? ''} » ?`} valider="Supprimer" danger onValider={() => supprimer(suppression)} onFermer={() => setSuppression(null)}>
          <p className="muted">Les lignes déjà enregistrées gardent leur catégorie.</p>
        </Modal>
      )}
    </div>
  );
}

/** Motif d'une règle avec ses mots ET et son type d'opération */
function DescriptionRegle({ regle }: { regle: Regle }) {
  return (
    <>
      <span className="mono-soft">
        {regle.motif}
        {regle.et?.map((m) => ` + ${m}`).join('')}
      </span>
      {regle.type_operation && <span className="badge">{TYPES_OPERATION[regle.type_operation] ?? regle.type_operation}</span>}
    </>
  );
}

interface MutualisationDialogProps {
  onFermer: () => void;
  onApplique: (message: string) => void;
}

/**
 * Propose les règles en double (cochées) et les regroupements possibles (à cocher soi-même) ; un regroupement qui
 * changerait la catégorie d'opérations est montré mais ne peut pas être choisi.
 */
function MutualisationDialog({ onFermer, onApplique }: MutualisationDialogProps) {
  const [analyse, setAnalyse] = useState<Mutualisation | null>(null);
  const [choix, setChoix] = useState<Set<string>>(new Set());
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);

  useEffect(() => {
    getMutualisation().then(
      (a) => {
        setAnalyse(a);
        setChoix(new Set(a.doublons.map((d) => d.clé)));
      },
      (e: Error) => setErreur(e.message),
    );
  }, []);

  function basculer(clé: string, coche: boolean) {
    setChoix((avant) => {
      const apres = new Set(avant);
      if (coche) apres.add(clé);
      else apres.delete(clé);
      return apres;
    });
  }

  async function appliquer() {
    setEnvoi(true);
    setErreur(null);
    try {
      const r = await appliquerMutualisation([...choix]);
      const creees = r.créées > 0 ? `, ${r.créées} créée${r.créées > 1 ? 's' : ''}` : '';
      onApplique(`${r.supprimées} règle${r.supprimées > 1 ? 's' : ''} supprimée${r.supprimées > 1 ? 's' : ''}${creees} (sauvegarde faite avant)`);
    } catch (e) {
      setErreur((e as Error).message);
      setEnvoi(false);
    }
  }

  const vide = analyse !== null && analyse.doublons.length === 0 && analyse.regroupements.length === 0;

  return (
    <Modal
      titre="Mutualiser les règles"
      valider={
        vide ? undefined : envoi ? (
          <span className="en-cours" role="status">
            <Spinner /> Application…
          </span>
        ) : (
          `Appliquer (${choix.size})`
        )
      }
      desactive={choix.size === 0 || envoi}
      erreur={erreur}
      onValider={appliquer}
      onFermer={onFermer}
    >
      {analyse === null ? (
        !erreur && (
          <p className="muted en-cours" role="status">
            <Spinner /> Analyse des règles et des opérations…
          </p>
        )
      ) : vide ? (
        <p className="muted">Rien à mutualiser : aucune règle en double ni regroupement possible.</p>
      ) : (
        <div className="mutualisation">
          <p className="muted small">
            Seules les modifications qui ne changent la catégorie d'aucune opération enregistrée sont applicables. Une
            sauvegarde de la base est faite avant.
          </p>
          {analyse.doublons.length > 0 && (
            <>
              <h3>Règles en double ({analyse.doublons.length})</h3>
              {analyse.doublons.map((d) => (
                <label key={d.clé} className="check">
                  <input type="checkbox" checked={choix.has(d.clé)} onChange={(e) => basculer(d.clé, e.target.checked)} />
                  <span>
                    <DescriptionRegle regle={d.règle} /> <span className="muted">couverte par</span> <DescriptionRegle regle={d.couverte_par} />
                    <br />
                    <span className="muted small">{libelleCategorie(categorieDe(d.règle))}</span>
                  </span>
                </label>
              ))}
            </>
          )}
          {analyse.regroupements.length > 0 && (
            <>
              <h3>Regroupements possibles ({analyse.regroupements.length})</h3>
              <p className="muted small">À vérifier : le mot commun peut aussi reconnaître d'autres marchands à l'avenir.</p>
              {analyse.regroupements.map((g) => (
                <label key={g.clé} className={'check' + (g.impact > 0 ? ' indisponible' : '')}>
                  <input
                    type="checkbox"
                    checked={choix.has(g.clé)}
                    disabled={g.impact > 0}
                    onChange={(e) => basculer(g.clé, e.target.checked)}
                  />
                  <span>
                    {g.règles.map((r, i) => (
                      <span key={r.id ?? i}>
                        {i > 0 && <span className="muted"> + </span>}
                        <DescriptionRegle regle={r} />
                      </span>
                    ))}{' '}
                    → <DescriptionRegle regle={g.nouvelle} />
                    <br />
                    <span className="muted small">{libelleCategorie(categorieDe(g.nouvelle))}</span>
                    {g.impact > 0 && (
                      <span className="small error">
                        <br />
                        Changerait {g.impact} opération{g.impact > 1 ? 's' : ''}, par exemple :
                        {g.exemples.map((ex) => (
                          <span key={ex.libelle}>
                            <br />« {ex.libelle} » : {ex.avant} → {ex.après}
                          </span>
                        ))}
                      </span>
                    )}
                  </span>
                </label>
              ))}
            </>
          )}
        </div>
      )}
    </Modal>
  );
}

function Testeur({ categories }: { categories: Categories }) {
  const [libelle, setLibelle] = useState('');
  const [sens, setSens] = useState<Sens>('dépense');
  const [resultat, setResultat] = useState<ResultatTest | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  async function tester() {
    setErreur(null);
    try {
      setResultat(await testerLibelle(libelle, sens));
    } catch (e) {
      setErreur((e as Error).message);
    }
  }

  return (
    <section className="card">
      <h2>🔍 Tester un libellé</h2>
      <form
        className="tester"
        onSubmit={(e) => {
          e.preventDefault();
          void tester();
        }}
      >
        <input
          className="search grow"
          placeholder="ex. Paiement par carte PAIEMENT PAR CARTE X1234 AUCHAN PEROLS 10/01"
          value={libelle}
          onChange={(e) => setLibelle(e.target.value)}
        />
        <select className="category-select" value={sens} onChange={(e) => setSens(e.target.value as Sens)}>
          <option value="dépense">Dépense</option>
          <option value="revenu">Revenu</option>
        </select>
        <button type="submit" className="btn" disabled={libelle.trim() === ''}>
          Tester
        </button>
      </form>
      {erreur && <p className="error">{erreur}</p>}
      {resultat && (
        <dl className="test-result appear">
          <dt>Type d'opération</dt>
          <dd>{TYPES_OPERATION[resultat.type_operation] ?? resultat.type_operation}</dd>
          <dt>Texte comparé aux motifs</dt>
          <dd className="mono-soft">{resultat.texte || '—'}</dd>
          <dt>Catégorie</dt>
          <dd>
            <CategoryPicker categories={categories} sens={sens} value={resultat.catégorie} onChange={() => undefined} disabled />
          </dd>
          <dt>Par</dt>
          <dd>
            {resultat.règle
              ? `la règle « ${resultat.règle.motif} » (${resultat.règle.origine})`
              : resultat.source === 'type'
                ? "le type d'opération (aucune règle)"
                : 'aucune règle'}
          </dd>
        </dl>
      )}
    </section>
  );
}

interface RegleDialogProps {
  /** null : nouvelle règle (apprise) */
  id: number | null;
  initiale: Regle;
  categories: Categories;
  onFermer: () => void;
  onEnregistre: (message: string) => void;
}

function RegleDialog({ id, initiale, categories, onFermer, onEnregistre }: RegleDialogProps) {
  const [motif, setMotif] = useState(initiale.motif);
  const [et, setEt] = useState((initiale.et ?? []).join(', '));
  const [sens, setSens] = useState<Sens>(initiale.type);
  const [categorie, setCategorie] = useState<Categorie>(categorieDe(initiale));
  const [typeOperation, setTypeOperation] = useState(initiale.type_operation ?? '');
  const [aConfirmer, setAConfirmer] = useState(initiale.a_confirmer ?? false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);

  function changerSens(s: Sens) {
    setSens(s);
    setCategorie(s === 'dépense' ? { catégorie1: A_CATEGORISER, catégorie2: 'Non classé' } : A_CATEGORISER);
  }

  async function enregistrer() {
    setEnvoi(true);
    setErreur(null);
    const saisie = {
      ...construireRegle(motif.trim(), sens, categorie, typeOperation || undefined),
      et: et.split(',').map((m) => m.trim()).filter(Boolean),
      a_confirmer: aConfirmer,
    };
    try {
      if (id === null) {
        await creerRegle(saisie);
        onEnregistre(`Règle « ${saisie.motif} » créée`);
      } else {
        await modifierRegle(id, saisie);
        onEnregistre(`Règle « ${saisie.motif} » modifiée`);
      }
    } catch (e) {
      setErreur((e as Error).message);
      setEnvoi(false);
    }
  }

  return (
    <Modal
      titre={id === null ? 'Nouvelle règle' : `Modifier la règle « ${initiale.motif} »`}
      valider="Enregistrer"
      desactive={motif.trim() === '' || envoi}
      erreur={erreur}
      onValider={enregistrer}
      onFermer={onFermer}
    >
      <label className="field">
        <span>Motif (mots entiers, sans accents ni casse)</span>
        <input value={motif} onChange={(e) => setMotif(e.target.value)} autoFocus maxLength={200} />
      </label>
      <label className="field">
        <span>Autres mots requis (séparés par des virgules)</span>
        <input value={et} onChange={(e) => setEt(e.target.value)} placeholder="ex. ASSU" />
      </label>
      <div className="field-row">
        <label className="field">
          <span>Sens</span>
          <select className="category-select" value={sens} onChange={(e) => changerSens(e.target.value as Sens)}>
            <option value="dépense">Dépense</option>
            <option value="revenu">Revenu</option>
          </select>
        </label>
        <label className="field grow">
          <span>Catégorie</span>
          <CategorySelect categories={categories} sens={sens} value={categorie} onChange={setCategorie} />
        </label>
      </div>
      <label className="field">
        <span>Type d'opération</span>
        <select className="category-select" value={typeOperation} onChange={(e) => setTypeOperation(e.target.value)}>
          <option value="">Tous</option>
          {Object.entries(TYPES_OPERATION).map(([code, nom]) => (
            <option key={code} value={code}>
              {nom}
            </option>
          ))}
        </select>
      </label>
      <label className="check">
        <input type="checkbox" checked={aConfirmer} onChange={(e) => setAConfirmer(e.target.checked)} />
        À confirmer à chaque import
      </label>
      {id !== null && <p className="muted small">Origine conservée : {initiale.origine}.</p>}
    </Modal>
  );
}
