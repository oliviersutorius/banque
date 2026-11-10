import { depuisOption, versOption } from '../categories';
import type { Categorie, Categories, Sens } from '../types';

interface Props {
  categories: Categories;
  sens: Sens;
  value: Categorie;
  onChange: (categorie: Categorie) => void;
  disabled?: boolean;
}

/** Liste déroulante des catégories existantes : 2 niveaux pour une dépense, 1 pour un revenu. */
export default function CategorySelect({ categories, sens, value, onChange, disabled }: Props) {
  return (
    <select
      className="category-select"
      value={versOption(value)}
      disabled={disabled}
      onChange={(e) => onChange(depuisOption(e.target.value, sens))}
    >
      {sens === 'dépense'
        ? categories.dépenses.map((c) => (
            <optgroup key={c.nom} label={`${c.icone} ${c.nom}`}>
              {c.sous_catégories.map((sub) => (
                <option key={sub} value={versOption({ catégorie1: c.nom, catégorie2: sub })}>
                  {sub}
                </option>
              ))}
            </optgroup>
          ))
        : categories.revenus.map((c) => (
            <option key={c.nom} value={c.nom}>
              {c.icone} {c.nom}
            </option>
          ))}
    </select>
  );
}
