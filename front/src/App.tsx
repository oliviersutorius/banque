import { useEffect, useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { api, type Sante } from './api';
import Placeholder from './pages/Placeholder';

const NAV = [
  { to: '/mois', label: 'Mois', icon: '📅' },
  { to: '/annee', label: 'Année', icon: '🗓️' },
  { to: '/import', label: 'Import', icon: '📥' },
  { to: '/categories', label: 'Catégories', icon: '🏷️' },
  { to: '/regles', label: 'Règles', icon: '🧩' },
  { to: '/parametres', label: 'Paramètres', icon: '⚙️' },
];

export default function App() {
  const [sante, setSante] = useState<Sante | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  useEffect(() => {
    api<Sante>('/sante').then(setSante, (e: Error) => setErreur(e.message));
  }, []);

  return (
    <div className="app">
      <header className="topbar">
        <span className="brand">🐷 Mes Comptes</span>
        <nav className="nav">
          {NAV.map((item) => (
            <NavLink key={item.to} to={item.to} className="nav-link">
              <span aria-hidden="true">{item.icon}</span> {item.label}
            </NavLink>
          ))}
        </nav>
        <span className={'status ' + (sante ? 'ok' : erreur ? 'ko' : '')} title={erreur ?? sante?.php ?? ''}>
          {sante ? 'API connectée' : erreur ? 'API injoignable' : '…'}
        </span>
      </header>
      <main className="content">
        <Routes>
          <Route path="/" element={<Navigate to="/mois" replace />} />
          <Route path="/mois" element={<Placeholder title="Vue mensuelle" step={5} />} />
          <Route path="/annee" element={<Placeholder title="Récapitulatif annuel" step={6} />} />
          <Route path="/import" element={<Placeholder title="Import d'un CSV" step={4} />} />
          <Route path="/categories" element={<Placeholder title="Catégories" step={7} />} />
          <Route path="/regles" element={<Placeholder title="Règles" step={7} />} />
          <Route path="/parametres" element={<Placeholder title="Paramètres" step={7} />} />
          <Route path="*" element={<Navigate to="/mois" replace />} />
        </Routes>
      </main>
    </div>
  );
}
