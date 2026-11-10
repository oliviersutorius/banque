import { useEffect, useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { getParametres, getSante, type Sante } from './api';
import CategoriesPage from './pages/CategoriesPage';
import ImportPage from './pages/ImportPage';
import MonthPage from './pages/MonthPage';
import RulesPage from './pages/RulesPage';
import SettingsPage from './pages/SettingsPage';
import YearPage from './pages/YearPage';
import { appliquerTheme } from './theme';

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
    getSante().then(setSante, (e: Error) => setErreur(e.message));
    getParametres().then((p) => appliquerTheme(p.thème), () => undefined);
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
          <Route path="/mois/:mois?" element={<MonthPage />} />
          <Route path="/annee/:annee?" element={<YearPage />} />
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
