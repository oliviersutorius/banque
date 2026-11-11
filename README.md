# Mes Comptes

Site personnel, utilisé uniquement en local, pour analyser ses dépenses et revenus à partir des exports CSV du Crédit Agricole.

- **Front** : React 18 + Vite + TypeScript, React Router, Recharts (`front/`), polices embarquées (aucune requête externe)
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

### Essayer sans toucher à ses données

La variable `BANQUE_DATA_DIR` fait travailler l'API sur un autre dossier que `data/` :

```bash
BANQUE_DATA_DIR=/tmp/banque-essai php -S localhost:8001 router.php
```

## Utilisation

1. **Import** : déposer un export CSV du Crédit Agricole. L'aperçu montre les lignes nouvelles, les doublons ignorés
   et les lignes à catégoriser (en tête). Corriger une catégorie propose de créer une règle et de l'appliquer
   aux lignes similaires. Rien n'est écrit avant « Importer ».
2. **Mois** et **Année** : totaux, graphiques en bâtons ou en anneau, tableau (matrice catégories × mois pour l'année).
   Un clic sur une catégorie affiche son détail ; chaque ligne peut être recatégorisée.
3. **À trier** : toutes les lignes encore « À catégoriser », tous mois confondus ; le badge du menu en donne le nombre.
4. **Catégories** : ajouter, renommer (partout), supprimer (avec une catégorie de remplacement), changer l'icône.
5. **Règles** : tester un libellé, créer, modifier, supprimer.
6. **Paramètres** : charte graphique (Guimauve, Sauge & Lin, Nuit Lavande).

Les fichiers JSON sont écrits de façon atomique ; avant chaque modification, une copie horodatée est gardée dans
`data/sauvegardes/` (les 20 dernières par fichier).

## API

```
POST   /api/import/preview                 CSV (multipart, champ « fichier ») → aperçu, rien n'est écrit
POST   /api/import/confirm                 { lignes } → écriture des mois, sans doublons
GET    /api/mois                           mois disponibles
GET    /api/mois/{YYYYMM}                  contenu d'un mois (+ libellés analysés)
PATCH  /api/mois/{YYYYMM}/lignes/{index}   { date, libelle, catégorie, autres? } → recatégorisation
GET    /api/annee/{YYYY}                   mois enregistrés de l'année
GET    /api/a-trier                        lignes « À catégoriser » de tous les mois (avec leur index)
GET    /api/categories                     liste des catégories
PUT    /api/categories                     { action: ajouter | icone | renommer | supprimer, … }
POST   /api/categories/usage               lignes et règles qui utilisent une catégorie
GET    /api/regles                         règles
POST   /api/regles                         { règle, appliquer_aux_mois } → règle apprise
POST   /api/regles/correspondances         lignes reconnues par une règle
POST   /api/regles/tester                  { libelle, sens } → catégorie obtenue et règle appliquée
PUT    /api/regles/{index}                 { motif_actuel, règle }
DELETE /api/regles/{index}?motif=…
GET    /api/parametres
PUT    /api/parametres                     { thème }
```

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
