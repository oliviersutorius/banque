import { Link } from 'react-router-dom';
import type { Matrice } from '../aggregate';
import { icone } from '../categories';
import { formatEuros } from '../format';
import { MOIS_COURTS } from '../months';
import type { Categories, Sens } from '../types';

interface Props {
  annee: string;
  sens: Sens;
  matrice: Matrice;
  categories: Categories;
  onSelect: (cat1: string) => void;
}

function Cellule({ cents }: { cents: number | null }) {
  if (cents === null) {
    return <td className="num muted" title="Mois non importé">—</td>;
  }
  return <td className={'num' + (cents === 0 ? ' muted' : '')}>{cents === 0 ? '·' : formatEuros(cents)}</td>;
}

/** Tableau annuel : catégories (lignes) × mois de janvier à décembre (colonnes), avec les totaux. */
export default function YearMatrix({ annee, sens, matrice, categories, onSelect }: Props) {
  if (matrice.lignes.length === 0) {
    return <p className="muted empty-small">Rien cette année 🌙</p>;
  }
  return (
    <div className="table-wrap appear">
      <table className="table matrix">
        <thead>
          <tr>
            <th>Catégorie</th>
            {MOIS_COURTS.map((m, i) => (
              <th key={m} className="num">
                {matrice.totauxMois[i] === null ? m : <Link to={`/mois/${annee}${String(i + 1).padStart(2, '0')}`}>{m}</Link>}
              </th>
            ))}
            <th className="num">Total</th>
          </tr>
        </thead>
        <tbody>
          {matrice.lignes.map((l) => (
            <tr key={l.nom} className="clickable" onClick={() => onSelect(l.nom)}>
              <td className="nowrap">
                {icone(categories, sens, l.nom)} {l.nom}
              </td>
              {l.parMois.map((cents, i) => (
                <Cellule key={i} cents={cents} />
              ))}
              <td className="num total">{formatEuros(l.total)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td>
            {matrice.totauxMois.map((cents, i) => (
              <Cellule key={i} cents={cents} />
            ))}
            <td className={'num total ' + (sens === 'dépense' ? 'depense' : 'revenu')}>{formatEuros(matrice.total)}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
