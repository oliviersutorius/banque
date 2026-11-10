import { useState } from 'react';
import { categorie1, icone, libelleCategorie } from '../categories';
import type { Categorie, Categories, Sens } from '../types';
import CategorySelect from './CategorySelect';

interface Props {
  categories: Categories;
  sens: Sens;
  value: Categorie;
  onChange: (categorie: Categorie) => void;
  disabled?: boolean;
}

/**
 * Catégorie affichée comme une pastille ; la liste déroulante n'est créée qu'au clic.
 * Un tableau de centaines de lignes reste ainsi léger (une liste compte plus de 50 options).
 */
export default function CategoryPicker({ categories, sens, value, onChange, disabled }: Props) {
  const [edition, setEdition] = useState(false);

  if (edition && !disabled) {
    return (
      <CategorySelect
        categories={categories}
        sens={sens}
        value={value}
        autoFocus
        onBlur={() => setEdition(false)}
        onChange={(c) => {
          setEdition(false);
          onChange(c);
        }}
      />
    );
  }
  return (
    <button type="button" className="category-pill" disabled={disabled} onClick={() => setEdition(true)} title="Changer la catégorie">
      <span aria-hidden="true">{icone(categories, sens, categorie1(value))}</span> {libelleCategorie(value)}
    </button>
  );
}
