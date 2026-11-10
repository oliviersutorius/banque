import { useEffect, useMemo, useState } from 'react';
import { creerRegle, getCategories, getRegles, modifierRegle, supprimerRegle, testerLibelle, type ResultatTest } from '../api';
import { A_CATEGORISER, libelleCategorie, regle as construireRegle } from '../categories';
import CategoryPicker from '../components/CategoryPicker';
import CategorySelect from '../components/CategorySelect';
import Modal from '../components/Modal';
import { TYPES_OPERATION } from '../operationTypes';
import type { Categorie, Categories, Regle, Sens } from '../types';

type Filtre = 'toutes' | 'apprise' | 'initiale';

const categorieDe = (r: Regle): Categorie =>
  r.type === 'dépense' ? { catégorie1: r.catégorie1 ?? '', catégorie2: r.catégorie2 ?? '' } : (r.catégorie ?? '');

export default function RulesPage() {
  const [regles, setRegles] = useState<Regle[] | null>(null);
  const [categories, setCategories] = useState<Categories | null>(null);
  const [filtre, setFiltre] = useState<Filtre>('toutes');
  const [recherche, setRecherche] = useState('');
  const [edition, setEdition] = useState<{ index: number | null; regle: Regle } | null>(null);
  const [suppression, setSuppression] = useState<number | null>(null);
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
      .map((r, index) => ({ r, index }))
      .filter(({ r }) => filtre === 'toutes' || r.origine === filtre)
      .filter(({ r }) => texte === '' || r.motif.toUpperCase().includes(texte) || libelleCategorie(categorieDe(r)).toUpperCase().includes(texte));
  }, [regles, filtre, recherche]);

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

  async function supprimer(index: number) {
    const r = regles?.[index];
    if (!r) return;
    try {
      await supprimerRegle(index, r.motif);
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
          <button type="button" className="btn" onClick={() => setEdition({ index: null, regle: nouvelle })}>
            + Règle
          </button>
        </header>
        <div className="filters">
          <input className="search" placeholder="Rechercher un motif ou une catégorie" value={recherche} onChange={(e) => setRecherche(e.target.value)} />
          <div className="switch" role="tablist">
            {(['toutes', 'apprise', 'initiale'] as const).map((f) => (
              <button key={f} type="button" role="tab" aria-selected={filtre === f} className={filtre === f ? 'active' : ''} onClick={() => setFiltre(f)}>
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
              {visibles.map(({ r, index }) => (
                <tr key={index}>
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
                    <button type="button" className="icon-btn" title="Modifier" onClick={() => setEdition({ index, regle: r })}>
                      ✏️
                    </button>
                    <button type="button" className="icon-btn" title="Supprimer" onClick={() => setSuppression(index)}>
                      🗑️
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {edition && (
        <RegleDialog
          index={edition.index}
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
      {suppression !== null && (
        <Modal titre={`Supprimer la règle « ${regles[suppression]?.motif ?? ''} » ?`} valider="Supprimer" danger onValider={() => supprimer(suppression)} onFermer={() => setSuppression(null)}>
          <p className="muted">Les lignes déjà enregistrées gardent leur catégorie.</p>
        </Modal>
      )}
    </div>
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
              ? `la règle n° ${resultat.règle.index + 1} « ${resultat.règle.motif} » (${resultat.règle.origine})`
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
  index: number | null;
  initiale: Regle;
  categories: Categories;
  onFermer: () => void;
  onEnregistre: (message: string) => void;
}

function RegleDialog({ index, initiale, categories, onFermer, onEnregistre }: RegleDialogProps) {
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
      if (index === null) {
        await creerRegle(saisie);
        onEnregistre(`Règle « ${saisie.motif} » créée`);
      } else {
        await modifierRegle(index, initiale.motif, saisie);
        onEnregistre(`Règle « ${saisie.motif} » modifiée`);
      }
    } catch (e) {
      setErreur((e as Error).message);
      setEnvoi(false);
    }
  }

  return (
    <Modal
      titre={index === null ? 'Nouvelle règle' : `Modifier la règle « ${initiale.motif} »`}
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
      {index !== null && <p className="muted small">Origine conservée : {initiale.origine}.</p>}
    </Modal>
  );
}
