import { useEffect, useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { EVENEMENT_DONNEES, deconnexion, getATrier, getParametres, getSante, getSession, type Sante } from './api';
import { EVENEMENT_SESSION, ecranPour, type EtatSession } from './auth';
import ATrierPage from './pages/ATrierPage';
import CategoriesPage from './pages/CategoriesPage';
import ImportPage from './pages/ImportPage';
import LoginPage from './pages/LoginPage';
import MonthPage from './pages/MonthPage';
import RulesPage from './pages/RulesPage';
import SettingsPage from './pages/SettingsPage';
import YearPage from './pages/YearPage';
import { appliquerTheme } from './theme';

const NAV = [
  { to: '/mois', label: 'Mois', icon: '📅' },
  { to: '/annee', label: 'Année', icon: '🗓️' },
  { to: '/a-trier', label: 'À trier', icon: '🧺' },
  { to: '/import', label: 'Import', icon: '📥' },
  { to: '/categories', label: 'Catégories', icon: '🏷️' },
  { to: '/regles', label: 'Règles', icon: '🧩' },
  { to: '/parametres', label: 'Paramètres', icon: '⚙️' },
];

/**
 * Vérifie la session au démarrage et après chaque 401 (session expirée) : page de connexion tant qu'on n'est pas
 * connecté, application sinon. L'URL est conservée : après reconnexion, on retrouve la même page.
 */
export default function App() {
  const [session, setSession] = useState<EtatSession | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  useEffect(() => {
    const verifier = () =>
      getSession().then(
        (etat) => {
          setSession(etat);
          setErreur(null);
        },
        (e: Error) => setErreur(e.message),
      );
    void verifier();
    window.addEventListener(EVENEMENT_SESSION, verifier);
    return () => window.removeEventListener(EVENEMENT_SESSION, verifier);
  }, []);

  if (erreur) {
    return (
      <main className="login">
        <p className="card error">API injoignable : {erreur}</p>
      </main>
    );
  }
  if (!session) {
    return null;
  }
  if (ecranPour(session) !== 'application') {
    return <LoginPage etat={session} onChange={setSession} />;
  }

  return (
    <Application
      identifiant={session.identifiant}
      onDeconnexion={() => {
        void deconnexion().finally(() => setSession({ ...session, etape: 'identifiants', csrf: null, identifiant: null }));
      }}
    />
  );
}

function Application({ identifiant, onDeconnexion }: { identifiant: string | null; onDeconnexion: () => void }) {
  const [sante, setSante] = useState<Sante | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [aTrier, setATrier] = useState(0);

  useEffect(() => {
    getSante().then(setSante, (e: Error) => setErreur(e.message));
    getParametres().then((p) => appliquerTheme(p.thème), () => undefined);

    // Badge « À trier » : recompté au démarrage et après chaque écriture (import, recatégorisation, règle…)
    const compter = () =>
      getATrier().then(
        (mois) => setATrier(mois.reduce((n, m) => n + m.lignes.length, 0)),
        () => undefined,
      );
    void compter();
    window.addEventListener(EVENEMENT_DONNEES, compter);
    return () => window.removeEventListener(EVENEMENT_DONNEES, compter);
  }, []);

  return (
    <div className="app">
      <header className="topbar">
        <span className="brand">🐷 Mes Comptes</span>
        <nav className="nav">
          {NAV.map((item) => (
            <NavLink key={item.to} to={item.to} className="nav-link">
              <span aria-hidden="true">{item.icon}</span> {item.label}
              {item.to === '/a-trier' && aTrier > 0 && (
                <span className="nav-badge" aria-label={`${aTrier} lignes à catégoriser`}>
                  {aTrier > 999 ? '999+' : aTrier}
                </span>
              )}
            </NavLink>
          ))}
        </nav>
        <span className={'status ' + (sante ? 'ok' : erreur ? 'ko' : '')} title={erreur ?? ''}>
          {sante ? 'API connectée' : erreur ? 'API injoignable' : '…'}
        </span>
        <button
          type="button"
          className="btn ghost logout"
          onClick={onDeconnexion}
          aria-label="Se déconnecter"
          title={identifiant ? `Connecté : ${identifiant}` : undefined}
        >
          Se déconnecter
        </button>
      </header>
      <main className="content">
        <Routes>
          <Route path="/" element={<Navigate to="/mois" replace />} />
          <Route path="/mois/:mois?" element={<MonthPage />} />
          <Route path="/annee/:annee?" element={<YearPage />} />
          <Route path="/a-trier" element={<ATrierPage />} />
          <Route path="/import" element={<ImportPage />} />
          <Route path="/categories" element={<CategoriesPage />} />
          <Route path="/regles" element={<RulesPage />} />
          <Route path="/parametres" element={<SettingsPage />} />
          <Route path="*" element={<Navigate to="/mois" replace />} />
        </Routes>
      </main>
    </div>
  );
}
