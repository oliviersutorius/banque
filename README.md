# Mes Comptes

Site personnel, utilisé uniquement en local, pour analyser ses dépenses et revenus à partir des exports CSV du Crédit Agricole.

- **Front** : React 18 + Vite + TypeScript, React Router, Recharts (`front/`)
- **API** : PHP 8.2+ sans framework (`api/`)
- **Stockage** : fichiers JSON dans `data/`, créé au premier lancement et **ignoré par git** (données bancaires personnelles)

## Prérequis

- PHP 8.2 ou plus récent, avec l'extension `mbstring`
- Node.js 20 ou plus récent et npm

## Installation

```bash
cd front
npm install
```

## Lancement

### Utilisation courante

Construire le front une fois, puis lancer le serveur PHP, qui sert à la fois l'API et le front :

```bash
cd front && npm run build && cd ..
php -S localhost:8000 router.php
```

Ouvrir <http://localhost:8000>.

### Développement

Dans deux terminaux :

```bash
# Terminal 1 : l'API
php -S localhost:8000 router.php

# Terminal 2 : le front avec rechargement à chaud
cd front && npm run dev
```

Ouvrir <http://localhost:5173>. Vite relaie les appels `/api` vers le port 8000.

## Tests

```bash
php api/tests/run.php            # tous les tests PHP
php api/tests/run.php CsvParser  # seulement ceux dont le nom contient « CsvParser »
cd front && npm test             # tests Vitest
```

## Organisation

```
api/index.php      routeur de l'API
api/src/           classes PHP (namespace Banque\)
api/tests/         tests PHP et leur lanceur maison (run.php)
front/             application React
data/              données (mois/, categories.json, regles.json, parametres.json, sauvegardes/)
router.php         point d'entrée de « php -S »
exemple.csv        extrait de relevé servant aux tests (non versionné)
```
