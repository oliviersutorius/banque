import { useEffect, useState } from 'react';

interface Props {
  /** Délai avant l'apparition (ms) : une réponse rapide ne fait pas clignoter l'indicateur */
  delai?: number;
}

/**
 * Indicateur de traitement en cours, à placer avant un texte qui dit ce qui se passe (le texte porte le sens pour
 * les lecteurs d'écran ; l'indicateur est décoratif). Sa place est réservée dès le début : rien ne bouge quand il
 * apparaît.
 */
export default function Spinner({ delai = 300 }: Props) {
  const [visible, setVisible] = useState(delai <= 0);

  useEffect(() => {
    if (delai <= 0) return;
    const minuteur = window.setTimeout(() => setVisible(true), delai);
    return () => window.clearTimeout(minuteur);
  }, [delai]);

  return <span className={'spinner' + (visible ? ' visible' : '')} aria-hidden="true" />;
}
