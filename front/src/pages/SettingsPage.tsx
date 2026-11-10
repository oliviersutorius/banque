import { useEffect, useState } from 'react';
import { getParametres, putParametres, type Parametres } from '../api';
import { THEMES, appliquerTheme } from '../theme';
import type { Theme } from '../types';

export default function SettingsPage() {
  const [parametres, setParametres] = useState<Parametres | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  useEffect(() => {
    getParametres().then(setParametres, (e: Error) => setErreur(e.message));
  }, []);

  async function choisir(theme: Theme) {
    appliquerTheme(theme);
    try {
      setParametres(await putParametres({ ...parametres, thème: theme }));
    } catch (e) {
      setErreur((e as Error).message);
    }
  }

  return (
    <div className="stack">
      <h1>⚙️ Paramètres</h1>
      {erreur && <p className="card error">{erreur}</p>}
      <section className="card">
        <h2>Charte graphique</h2>
        <div className="themes" role="radiogroup" aria-label="Charte graphique">
          {THEMES.map((t) => (
            <button
              key={t.valeur}
              type="button"
              role="radio"
              aria-checked={parametres?.thème === t.valeur}
              className={'theme-card' + (parametres?.thème === t.valeur ? ' active' : '')}
              onClick={() => void choisir(t.valeur)}
            >
              <span className="swatches">
                {t.apercu.map((c) => (
                  <span key={c} style={{ background: c }} />
                ))}
              </span>
              <strong>{t.nom}</strong>
              <span className="muted small">{t.description}</span>
            </button>
          ))}
        </div>
      </section>
    </div>
  );
}
