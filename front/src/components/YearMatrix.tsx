import { Link } from 'react-router-dom';
import type { Matrice } from '../aggregate';
import { icone } from '../categories';
import { formatEuros } from '../format';
import { libelleMois, MOIS_COURTS } from '../months';
import type { Categories, Sens } from '../types';

interface Props {
  annee: string;
  sens: Sens;
  matrice: Matrice;
  categories: Categories;
  /** Sans `mois` (AAAAMM) : détail annuel de la catégorie */
  onSelect: (cat1: string, mois?: string) => void;
}

function Cellule({ cents, mois, onClick }: { cents: number | null; mois?: string; onClick?: () => void }) {
  if (cents === null) {
    return <td className="num muted" title="Mois non importé">—</td>;
  }
  if (cents === 0) {
    return <td className="num muted">·</td>;
  }
  if (onClick && mois) {
    return (
      <td className="num clickable" title={`Voir les lignes de ${libelleMois(mois)}`} onClick={onClick}>
        {formatEuros(cents)}
      </td>
    );
  }
  return <td className="num">{formatEuros(cents)}</td>;
}

const colonneMois = (annee: string, i: number) => `${annee}${String(i + 1).padStart(2, '0')}`;

/** Tableau annuel : catégories (lignes) × mois de janvier à décembre (colonnes), avec les totaux et la moyenne mensuelle. */
export default function YearMatrix({ annee, sens, matrice, categories, onSelect }: Props) {
  if (matrice.lignes.length === 0) {
    return <p className="muted empty-small">Rien cette année 🌙</p>;
  }
  const couleur = sens === 'dépense' ? 'depense' : 'revenu';
  return (
    <div className="table-wrap appear">
      <table className="table matrix">
        <thead>
          <tr>
            <th>Catégorie</th>
            {MOIS_COURTS.map((m, i) => (
              <th key={m} className="num">
                {matrice.totauxMois[i] === null ? m : <Link to={`/mois/${colonneMois(annee, i)}`}>{m}</Link>}
              </th>
            ))}
            <th className="num col-total">Total</th>
            <th className="num col-moyenne" title={`Moyenne sur ${matrice.nbMois} mois enregistré(s)`}>
              Moyenne
            </th>
          </tr>
        </thead>
        <tbody>
          {matrice.lignes.map((l) => (
            <tr key={l.nom}>
              <td className="nowrap clickable" title="Voir les lignes de l'année" onClick={() => onSelect(l.nom)}>
                {icone(categories, sens, l.nom)} {l.nom}
              </td>
              {l.parMois.map((cents, i) => (
                <Cellule key={i} cents={cents} mois={colonneMois(annee, i)} onClick={() => onSelect(l.nom, colonneMois(annee, i))} />
              ))}
              <td className="num total col-total clickable" title="Voir les lignes de l'année" onClick={() => onSelect(l.nom)}>{formatEuros(l.total)}</td>
              <td className="num col-moyenne">{formatEuros(l.moyenne)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td>
            {matrice.totauxMois.map((cents, i) => (
              <Cellule key={i} cents={cents} />
            ))}
            <td className={'num total col-total ' + couleur}>{formatEuros(matrice.total)}</td>
            <td className={'num col-moyenne ' + couleur}>{formatEuros(matrice.moyenne)}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
