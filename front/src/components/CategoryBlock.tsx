import type { ReactNode } from 'react';
import { Bar, BarChart, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { Part } from '../aggregate';
import { icone } from '../categories';
import { couleur } from '../colors';
import { formatEuros } from '../format';
import type { Categories, Sens } from '../types';

export type Affichage = 'batons' | 'camembert' | 'tableau';

const AFFICHAGES: { valeur: Affichage; libelle: string; icone: string }[] = [
  { valeur: 'batons', libelle: 'Bâtons', icone: '📊' },
  { valeur: 'camembert', libelle: 'Camembert', icone: '🍩' },
  { valeur: 'tableau', libelle: 'Tableau', icone: '📋' },
];

interface Props {
  titre: string;
  sens: Sens;
  parts: Part[];
  categories: Categories;
  affichage: Affichage;
  onAffichage: (a: Affichage) => void;
  /** Clic sur une catégorie 1 (barre, part du camembert, ligne de sous-total) */
  onSelect: (cat1: string) => void;
  selection: string | null;
  /** Contenu de l'affichage « Tableau » */
  tableau: ReactNode;
}

interface Donnee {
  nom: string;
  libelle: string;
  cents: number;
  part: number;
  fill: string;
}

function Infobulle({ active, payload }: { active?: boolean; payload?: { payload: Donnee }[] }) {
  const d = payload?.[0]?.payload;
  if (!active || !d) {
    return null;
  }
  return (
    <div className="chart-tooltip">
      <strong>{d.libelle}</strong>
      <span>
        {formatEuros(d.cents)} · {Math.round(d.part * 1000) / 10} %
      </span>
    </div>
  );
}

/** Bloc « Dépenses par catégorie » ou « Revenus par catégorie » avec ses 3 affichages. */
export default function CategoryBlock({ titre, sens, parts, categories, affichage, onAffichage, onSelect, selection, tableau }: Props) {
  const donnees: Donnee[] = parts.map((p) => ({
    nom: p.nom,
    libelle: `${icone(categories, sens, p.nom)} ${p.nom}`,
    cents: p.cents,
    part: p.part,
    fill: couleur(categories, sens, p.nom),
  }));
  const total = parts.reduce((s, p) => s + p.cents, 0);
  // Recharts passe la donnée cliquée sous une forme qui lui est propre : on retrouve la catégorie par son rang
  const clic = (_: unknown, index: number) => {
    const d = donnees[index];
    if (d) {
      onSelect(d.nom);
    }
  };
  // Sur un petit écran, les noms de catégories laissent plus de place aux barres
  const largeurNoms = window.matchMedia('(max-width: 480px)').matches ? 120 : 170;

  return (
    <section className="card block">
      <header className="section-head">
        <h2>{titre}</h2>
        <div className="switch" role="tablist" aria-label="Affichage">
          {AFFICHAGES.map((a) => (
            <button
              key={a.valeur}
              type="button"
              role="tab"
              aria-selected={affichage === a.valeur}
              className={affichage === a.valeur ? 'active' : ''}
              onClick={() => onAffichage(a.valeur)}
              title={a.libelle}
            >
              <span aria-hidden="true">{a.icone}</span>
              <span className="switch-label">{a.libelle}</span>
            </button>
          ))}
        </div>
      </header>

      {parts.length === 0 ? (
        <p className="muted empty-small">Rien ce mois-ci 🌙</p>
      ) : affichage === 'batons' ? (
        <div className="appear">
          <ResponsiveContainer width="100%" height={Math.max(120, donnees.length * 40)}>
            <BarChart data={donnees} layout="vertical" margin={{ left: 8, right: 16 }}>
              <XAxis type="number" hide />
              <YAxis type="category" dataKey="libelle" width={largeurNoms} tickLine={false} axisLine={false} tick={{ fontSize: 13, fill: 'var(--text)' }} />
              <Tooltip content={<Infobulle />} cursor={{ fill: 'var(--bg)' }} />
              <Bar dataKey="cents" radius={[0, 10, 10, 0]} barSize={22} animationDuration={700} onClick={clic} className="clickable">
                {donnees.map((d) => (
                  <Cell key={d.nom} fill={d.fill} opacity={selection && selection !== d.nom ? 0.4 : 1} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
      ) : affichage === 'camembert' ? (
        <div className="donut appear">
          <div className="donut-chart">
            <ResponsiveContainer width="100%" height={240}>
              <PieChart>
                <Pie
                  data={donnees}
                  dataKey="cents"
                  nameKey="libelle"
                  innerRadius="58%"
                  outerRadius="90%"
                  paddingAngle={2}
                  cornerRadius={6}
                  animationDuration={700}
                  onClick={clic}
                  className="clickable"
                >
                  {donnees.map((d) => (
                    <Cell key={d.nom} fill={d.fill} stroke="var(--card)" opacity={selection && selection !== d.nom ? 0.4 : 1} />
                  ))}
                </Pie>
                <Tooltip content={<Infobulle />} />
              </PieChart>
            </ResponsiveContainer>
            <div className="donut-center">
              <span className="muted small">Total</span>
              <strong>{formatEuros(total)}</strong>
            </div>
          </div>
          <ul className="legend">
            {donnees.map((d) => (
              <li key={d.nom}>
                <button type="button" className={selection === d.nom ? 'active' : ''} onClick={() => onSelect(d.nom)}>
                  <span className="dot" style={{ background: d.fill }} />
                  <span className="legend-name">{d.libelle}</span>
                  <span className="legend-value">{Math.round(d.part * 100)} %</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        tableau
      )}
    </section>
  );
}
