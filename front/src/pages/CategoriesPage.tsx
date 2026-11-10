import { useEffect, useState } from 'react';
import { getCategories, modifierCategories, usageCategorie, type ActionCategorie, type Usage } from '../api';
import { A_CATEGORISER } from '../categories';
import CategorySelect from '../components/CategorySelect';
import Modal from '../components/Modal';
import type { Categorie, Categories, Sens } from '../types';

/** Opération en cours d'édition dans la fenêtre */
type Edition =
  | { type: 'ajouter'; sens: Sens; parent?: string }
  | { type: 'icone'; sens: Sens; nom: string; icone: string }
  | { type: 'renommer'; sens: Sens; chemin: string[] }
  | { type: 'supprimer'; sens: Sens; chemin: string[] };

const pluriel = (n: number, mot: string) => `${n} ${mot}${n > 1 ? 's' : ''}`;

export default function CategoriesPage() {
  const [categories, setCategories] = useState<Categories | null>(null);
  const [edition, setEdition] = useState<Edition | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  useEffect(() => {
    getCategories().then(setCategories, (e: Error) => setErreur(e.message));
  }, []);

  async function appliquer(action: ActionCategorie, succes: (u: Usage) => string) {
    const r = await modifierCategories(action);
    setCategories(r.catégories);
    setEdition(null);
    setMessage(succes(r.modifiés));
  }

  if (!categories) {
    return erreur ? <p className="card error">{erreur}</p> : <p className="muted">Chargement…</p>;
  }

  const actions = (sens: Sens, chemin: string[]) =>
    chemin[0] === A_CATEGORISER ? null : (
      <span className="row-actions">
        <button type="button" className="icon-btn" title="Renommer" onClick={() => setEdition({ type: 'renommer', sens, chemin })}>
          ✏️
        </button>
        <button type="button" className="icon-btn" title="Supprimer" onClick={() => setEdition({ type: 'supprimer', sens, chemin })}>
          🗑️
        </button>
      </span>
    );

  const iconeBouton = (sens: Sens, nom: string, icone: string) => (
    <button
      type="button"
      className="emoji-btn"
      title="Changer l'icône"
      disabled={nom === A_CATEGORISER}
      onClick={() => setEdition({ type: 'icone', sens, nom, icone })}
    >
      {icone}
    </button>
  );

  return (
    <div className="stack">
      <h1>🏷️ Catégories</h1>
      {message && <p className="toast">{message}</p>}

      <section className="card">
        <header className="section-head">
          <h2>Dépenses</h2>
          <button type="button" className="btn" onClick={() => setEdition({ type: 'ajouter', sens: 'dépense' })}>
            + Catégorie
          </button>
        </header>
        <ul className="cat-list">
          {categories.dépenses.map((c) => (
            <li key={c.nom}>
              <div className="cat-row">
                {iconeBouton('dépense', c.nom, c.icone)}
                <strong>{c.nom}</strong>
                {actions('dépense', [c.nom])}
              </div>
              <div className="chips">
                {c.sous_catégories.map((s) => (
                  <span key={s} className="chip">
                    {s}
                    {c.nom !== A_CATEGORISER && (
                      <>
                        <button type="button" title={`Renommer « ${s} »`} onClick={() => setEdition({ type: 'renommer', sens: 'dépense', chemin: [c.nom, s] })}>
                          ✏️
                        </button>
                        <button type="button" title={`Supprimer « ${s} »`} onClick={() => setEdition({ type: 'supprimer', sens: 'dépense', chemin: [c.nom, s] })}>
                          ✕
                        </button>
                      </>
                    )}
                  </span>
                ))}
                {c.nom !== A_CATEGORISER && (
                  <button type="button" className="chip add" onClick={() => setEdition({ type: 'ajouter', sens: 'dépense', parent: c.nom })}>
                    + sous-catégorie
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </section>

      <section className="card">
        <header className="section-head">
          <h2>Revenus</h2>
          <button type="button" className="btn" onClick={() => setEdition({ type: 'ajouter', sens: 'revenu' })}>
            + Catégorie
          </button>
        </header>
        <ul className="cat-list">
          {categories.revenus.map((c) => (
            <li key={c.nom}>
              <div className="cat-row">
                {iconeBouton('revenu', c.nom, c.icone)}
                <strong>{c.nom}</strong>
                {actions('revenu', [c.nom])}
              </div>
            </li>
          ))}
        </ul>
      </section>

      {edition && (
        <EditionDialog key={JSON.stringify(edition)} edition={edition} categories={categories} appliquer={appliquer} onFermer={() => setEdition(null)} />
      )}
    </div>
  );
}

interface DialogProps {
  edition: Edition;
  categories: Categories;
  appliquer: (action: ActionCategorie, succes: (u: Usage) => string) => Promise<void>;
  onFermer: () => void;
}

function EditionDialog({ edition, categories, appliquer, onFermer }: DialogProps) {
  const ancienNom = 'chemin' in edition ? (edition.chemin[edition.chemin.length - 1] ?? '') : '';
  const [nom, setNom] = useState(edition.type === 'renommer' ? ancienNom : '');
  const [icone, setIcone] = useState(edition.type === 'icone' ? edition.icone : '✨');
  const [sousCategorie, setSousCategorie] = useState('');
  const [remplacement, setRemplacement] = useState<Categorie>(edition.sens === 'dépense' ? { catégorie1: A_CATEGORISER, catégorie2: 'Non classé' } : A_CATEGORISER);
  const [usage, setUsage] = useState<Usage | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);

  useEffect(() => {
    if (edition.type === 'renommer' || edition.type === 'supprimer') {
      usageCategorie(edition.sens, edition.chemin).then(setUsage, (e: Error) => setErreur(e.message));
    }
  }, [edition]);

  async function valider(action: ActionCategorie, succes: (u: Usage) => string) {
    setEnvoi(true);
    setErreur(null);
    try {
      await appliquer(action, succes);
    } catch (e) {
      setErreur((e as Error).message);
      setEnvoi(false);
    }
  }

  const impact = usage && (
    <p className="muted">
      Utilisée par {pluriel(usage.lignes, 'ligne')} enregistrée(s) et {pluriel(usage.règles, 'règle')}.
    </p>
  );
  const commun = { erreur, onFermer };

  switch (edition.type) {
    case 'ajouter': {
      const cat1Depense = edition.sens === 'dépense' && !edition.parent;
      const ok = nom.trim() !== '' && (!cat1Depense || sousCategorie.trim() !== '') && (edition.parent !== undefined || icone.trim() !== '');
      return (
        <Modal
          titre={edition.parent ? `Nouvelle sous-catégorie de « ${edition.parent} »` : `Nouvelle catégorie de ${edition.sens === 'dépense' ? 'dépense' : 'revenu'}`}
          valider="Ajouter"
          desactive={!ok || envoi}
          onValider={() =>
            valider(
              {
                action: 'ajouter',
                sens: edition.sens,
                chemin: edition.parent ? [edition.parent, nom] : [nom],
                icone,
                sous_catégorie: sousCategorie,
              },
              () => `« ${nom.trim()} » ajoutée`,
            )
          }
          {...commun}
        >
          <div className="field-row">
            {!edition.parent && (
              <label className="field emoji-field">
                <span>Icône</span>
                <input value={icone} onChange={(e) => setIcone(e.target.value)} />
              </label>
            )}
            <label className="field grow">
              <span>Nom</span>
              <input value={nom} onChange={(e) => setNom(e.target.value)} autoFocus maxLength={60} />
            </label>
          </div>
          {cat1Depense && (
            <label className="field">
              <span>Première sous-catégorie</span>
              <input value={sousCategorie} onChange={(e) => setSousCategorie(e.target.value)} maxLength={60} placeholder="ex. Divers" />
            </label>
          )}
        </Modal>
      );
    }
    case 'icone':
      return (
        <Modal
          titre={`Icône de « ${edition.nom} »`}
          valider="Enregistrer"
          desactive={icone.trim() === '' || envoi}
          onValider={() => valider({ action: 'icone', sens: edition.sens, chemin: [edition.nom], icone }, () => 'Icône mise à jour')}
          {...commun}
        >
          <label className="field emoji-field big">
            <span>Un emoji</span>
            <input value={icone} onChange={(e) => setIcone(e.target.value)} autoFocus />
          </label>
        </Modal>
      );
    case 'renommer':
      return (
        <Modal
          titre={`Renommer « ${ancienNom} »`}
          valider={usage ? `Renommer partout` : 'Renommer'}
          desactive={!usage || nom.trim() === '' || nom.trim() === ancienNom || envoi}
          onValider={() =>
            valider(
              { action: 'renommer', sens: edition.sens, chemin: edition.chemin, nom },
              (u) => `Renommée : ${pluriel(u.lignes, 'ligne')} et ${pluriel(u.règles, 'règle')} mises à jour`,
            )
          }
          {...commun}
        >
          <label className="field">
            <span>Nouveau nom</span>
            <input value={nom} onChange={(e) => setNom(e.target.value)} autoFocus maxLength={60} />
          </label>
          {impact}
          {usage && usage.lignes + usage.règles > 0 && <p className="small">Tous les mois enregistrés et toutes les règles seront mis à jour.</p>}
        </Modal>
      );
    case 'supprimer': {
      const utilisee = usage !== null && usage.lignes + usage.règles > 0;
      return (
        <Modal
          titre={`Supprimer « ${ancienNom} » ?`}
          valider="Supprimer"
          danger
          desactive={!usage || envoi}
          onValider={() =>
            valider(
              {
                action: 'supprimer',
                sens: edition.sens,
                chemin: edition.chemin,
                remplacement: utilisee
                  ? typeof remplacement === 'string'
                    ? [remplacement]
                    : [remplacement.catégorie1, remplacement.catégorie2]
                  : undefined,
              },
              (u) => (utilisee ? `Supprimée : ${pluriel(u.lignes, 'ligne')} et ${pluriel(u.règles, 'règle')} déplacées` : 'Supprimée'),
            )
          }
          {...commun}
        >
          {impact}
          {utilisee && (
            <label className="field">
              <span>Déplacer ces lignes et ces règles vers</span>
              <CategorySelect categories={categories} sens={edition.sens} value={remplacement} onChange={setRemplacement} />
            </label>
          )}
        </Modal>
      );
    }
  }
}
