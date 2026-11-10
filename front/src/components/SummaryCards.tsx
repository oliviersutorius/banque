import { formatEuros } from '../format';

interface Props {
  dépenses: number;
  revenus: number;
  solde: number;
  /** « ce mois-ci », « cette année »… pour le message d'encouragement */
  periode: string;
}

export default function SummaryCards({ dépenses, revenus, solde, periode }: Props) {
  return (
    <>
      <section className="summary">
        <div className="card stat">
          <span className="muted">Dépenses</span>
          <strong className="depense">{formatEuros(dépenses)}</strong>
        </div>
        <div className="card stat">
          <span className="muted">Revenus</span>
          <strong className="revenu">{formatEuros(revenus)}</strong>
        </div>
        <div className="card stat">
          <span className="muted">Solde</span>
          <strong className={solde >= 0 ? 'revenu' : 'depense'}>
            {solde > 0 ? '+' : ''}
            {formatEuros(solde)}
          </strong>
        </div>
      </section>
      {solde > 0 && (
        <p className="cheer appear">
          🌱 Bravo, tu as mis {formatEuros(solde)} de côté {periode} !
        </p>
      )}
    </>
  );
}
