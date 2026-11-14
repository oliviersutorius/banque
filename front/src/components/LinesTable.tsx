import { chronologique, type LigneVue, type Part } from '../aggregate';
import { estACategoriser, icone } from '../categories';
import { formatEuros } from '../format';
import type { Categorie, Categories } from '../types';
import CategoryPicker from './CategoryPicker';

interface Props {
  lignes: LigneVue[];
  categories: Categories;
  onRecategoriser: (ligne: LigneVue, categorie: Categorie) => void;
  /** Sous-totaux par catégorie 1 en pied de tableau (cliquables) */
  sousTotaux?: Part[];
  onSelect?: (cat1: string) => void;
}

/** Lignes en ordre chronologique : date, libellé, catégorie (modifiable), montant. */
export default function LinesTable({ lignes, categories, onRecategoriser, sousTotaux, onSelect }: Props) {
  if (lignes.length === 0) {
    return <p className="muted empty-small">Aucune ligne</p>;
  }
  return (
    <div className="table-wrap appear">
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
          {chronologique(lignes).map((l) => (
            <tr key={l.id} className={estACategoriser(l.catégorie) ? 'review' : ''}>
              <td className="nowrap">{l.date}</td>
              <td className="libelle">{l.libelle}</td>
              <td>
                <CategoryPicker categories={categories} sens={l.sens} value={l.catégorie} onChange={(c) => onRecategoriser(l, c)} />
              </td>
              <td className={'num nowrap ' + (l.sens === 'dépense' ? 'depense' : 'revenu')}>{formatEuros(l.cents)}</td>
            </tr>
          ))}
        </tbody>
        {sousTotaux && (
          <tfoot>
            {sousTotaux.map((p) => (
              <tr key={p.nom} className="subtotal clickable" onClick={() => onSelect?.(p.nom)}>
                <td colSpan={3}>
                  {icone(categories, lignes[0]?.sens ?? 'dépense', p.nom)} {p.nom}{' '}
                  <span className="muted small">({p.nombre})</span>
                </td>
                <td className="num nowrap">{formatEuros(p.cents)}</td>
              </tr>
            ))}
          </tfoot>
        )}
      </table>
    </div>
  );
}
