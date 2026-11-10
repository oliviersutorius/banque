import { parCategorie2, type LigneVue } from '../aggregate';
import { categorie1, icone } from '../categories';
import { couleur, couleurSousCategorie } from '../colors';
import { formatEuros } from '../format';
import type { Categorie, Categories, Sens } from '../types';
import LinesTable from './LinesTable';

interface Props {
  cat1: string;
  sens: Sens;
  lignes: LigneVue[];
  categories: Categories;
  onRecategoriser: (ligne: LigneVue, categorie: Categorie) => void;
  onClose: () => void;
}

/** Détail d'une catégorie 1 : répartition par catégorie 2 et lignes concernées. */
export default function CategoryDetail({ cat1, sens, lignes, categories, onRecategoriser, onClose }: Props) {
  const concernees = lignes.filter((l) => l.sens === sens && categorie1(l.catégorie) === cat1);
  const total = concernees.reduce((s, l) => s + l.cents, 0);
  const sousCategories = sens === 'dépense' ? parCategorie2(lignes, cat1) : [];
  const base = couleur(categories, sens, cat1);

  return (
    <section className="card detail appear">
      <header className="section-head">
        <h2>
          {icone(categories, sens, cat1)} {cat1}
        </h2>
        <span>
          <strong className={sens === 'dépense' ? 'depense' : 'revenu'}>{formatEuros(total)}</strong>
          <button type="button" className="btn-close" onClick={onClose} aria-label="Fermer le détail">
            ✕
          </button>
        </span>
      </header>

      {sousCategories.length > 0 && (
        <ul className="sub-bars">
          {sousCategories.map((p, rang) => (
            <li key={p.nom}>
              <span className="sub-name">{p.nom}</span>
              <span className="sub-track">
                <span className="sub-fill" style={{ width: `${Math.max(2, p.part * 100)}%`, background: couleurSousCategorie(base, rang) }} />
              </span>
              <span className="sub-value">
                {formatEuros(p.cents)} <span className="muted small">{Math.round(p.part * 100)} %</span>
              </span>
            </li>
          ))}
        </ul>
      )}

      <LinesTable lignes={concernees} categories={categories} onRecategoriser={onRecategoriser} />
    </section>
  );
}
