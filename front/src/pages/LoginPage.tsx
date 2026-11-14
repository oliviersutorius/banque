import { useState, type FormEvent } from 'react';
import { ApiError, connexion, connexionCode } from '../api';
import { ecranPour, nettoyerCodeTotp, type EtatSession } from '../auth';

interface Props {
  etat: EtatSession;
  /** Nouvel état après chaque étape (mot de passe accepté, code accepté…) */
  onChange: (etat: EtatSession) => void;
}

/**
 * Connexion en deux temps : identifiant + mot de passe, puis code de l'application d'authentification
 * (ou code de secours). Si la double authentification est désactivée (poste local), la première étape suffit.
 */
export default function LoginPage({ etat, onChange }: Props) {
  const ecran = ecranPour(etat);
  const [identifiant, setIdentifiant] = useState('');
  const [motDePasse, setMotDePasse] = useState('');
  const [code, setCode] = useState('');
  const [secours, setSecours] = useState(false);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  async function envoyer(e: FormEvent, action: () => Promise<EtatSession>) {
    e.preventDefault();
    setEnvoi(true);
    setErreur(null);
    try {
      const nouvelEtat = await action();
      setMotDePasse('');
      setCode('');
      onChange(nouvelEtat);
    } catch (err) {
      setErreur((err as Error).message);
      if (err instanceof ApiError && err.status === 401 && ecran === 'code' && /expirée/.test(err.message)) {
        // Délai de 5 min dépassé : retour à la saisie du mot de passe
        onChange({ ...etat, etape: 'identifiants' });
      }
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <main className="login">
      <div className="card login-card">
        <p className="brand login-brand">🐷 Mes Comptes</p>

        {ecran === 'sans-compte' && (
          <>
            <h1>Aucun compte</h1>
            <p>Le compte se crée sur la machine qui héberge le site, dans un terminal :</p>
            <pre className="login-command">php api/bin/compte.php init</pre>
            <p className="muted small">Puis rechargez cette page.</p>
          </>
        )}

        {ecran === 'identifiants' && (
          <form onSubmit={(e) => void envoyer(e, () => connexion(identifiant, motDePasse))}>
            <h1>Connexion</h1>
            <label className="field">
              <span>Identifiant</span>
              <input
                name="username"
                autoComplete="username"
                value={identifiant}
                onChange={(e) => setIdentifiant(e.target.value)}
                required
                autoFocus
              />
            </label>
            <label className="field">
              <span>Mot de passe</span>
              <input
                name="password"
                type="password"
                autoComplete="current-password"
                value={motDePasse}
                onChange={(e) => setMotDePasse(e.target.value)}
                required
              />
            </label>
            {erreur && <p className="error" role="alert">{erreur}</p>}
            <button type="submit" className="btn login-submit" disabled={envoi || !identifiant || !motDePasse}>
              {envoi ? 'Vérification…' : etat.totp ? 'Continuer' : 'Se connecter'}
            </button>
          </form>
        )}

        {ecran === 'code' && (
          <form onSubmit={(e) => void envoyer(e, () => connexionCode(code))}>
            <h1>Double authentification</h1>
            {secours ? (
              <label className="field">
                <span>Code de secours</span>
                <input
                  name="recovery-code"
                  autoComplete="off"
                  placeholder="abcde-fghij"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  required
                  autoFocus
                />
                <small className="muted">Chaque code de secours ne sert qu'une fois.</small>
              </label>
            ) : (
              <label className="field">
                <span>Code à 6 chiffres de votre application d'authentification</span>
                <input
                  name="one-time-code"
                  className="login-code"
                  autoComplete="one-time-code"
                  inputMode="numeric"
                  pattern="\d{6}"
                  maxLength={6}
                  value={code}
                  onChange={(e) => setCode(nettoyerCodeTotp(e.target.value))}
                  required
                  autoFocus
                />
              </label>
            )}
            {erreur && <p className="error" role="alert">{erreur}</p>}
            <button
              type="submit"
              className="btn login-submit"
              disabled={envoi || (secours ? code.trim() === '' : code.length !== 6)}
            >
              {envoi ? 'Vérification…' : 'Se connecter'}
            </button>
            <button
              type="button"
              className="link-button"
              onClick={() => {
                setSecours(!secours);
                setCode('');
                setErreur(null);
              }}
            >
              {secours ? 'Utiliser le code de l’application' : 'Utiliser un code de secours'}
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
