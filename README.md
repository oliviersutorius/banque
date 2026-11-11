# Mes Comptes

Site personnel, utilisé uniquement en local, pour analyser ses dépenses et revenus à partir des exports CSV du Crédit Agricole.

- **Front** : React 18 + Vite + TypeScript, React Router, Recharts (`front/`), polices embarquées (aucune requête externe)
- **API** : PHP 8.2+ sans framework (`api/`)
- **Stockage** : fichiers JSON dans `data/`, créé au premier lancement et **ignoré par git** (données bancaires personnelles)

Documentation :

- [docs/FONCTIONNEL.md](docs/FONCTIONNEL.md) — utilisation, import et doublons, règles et apprentissage, catégories
- [docs/TECHNIQUE.md](docs/TECHNIQUE.md) — architecture, formats JSON, API, sécurité, tests, écarts au cahier des charges
- [PROMPT.md](PROMPT.md) — cahier des charges d'origine

## Prérequis

- PHP 8.2 ou plus récent, avec l'extension `mbstring`
- Node.js **22.12** ou plus récent et npm (exigé par Vite 8 et Vitest 5)

## Installation

```bash
cd front
npm install
```

## Lancement

### Utilisation courante

Construire le front une fois (et après chaque mise à jour du code), puis lancer le serveur PHP, qui sert à la fois
l'API et le front :

```bash
cd front && npm run build && cd .. && php -S localhost:8000 router.php
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

Ouvrir <http://localhost:5173>. Vite relaie les appels `/api` vers le port 8000 (donc vers les vraies données).

### Essayer sans toucher à ses données

La variable `BANQUE_DATA_DIR` fait travailler l'API sur un autre dossier que `data/`. Elle sert le front construit
(`npm run build`) :

```bash
# Optionnel : partir de ses vraies catégories et règles
mkdir -p /tmp/banque-essai && cp data/categories.json data/regles.json /tmp/banque-essai/

BANQUE_DATA_DIR=/tmp/banque-essai php -S localhost:8001 router.php
```

Ouvrir <http://localhost:8001>. Pour recommencer : arrêter le serveur et supprimer `/tmp/banque-essai`.

Pour un simple aperçu d'un fichier, la page Import suffit : rien n'est enregistré avant « Importer », sauf si l'on
crée une règle dans la fenêtre d'apprentissage (voir [FONCTIONNEL.md](docs/FONCTIONNEL.md#les-deux-temps-de-limport)).

## Utilisation en bref

1. **Import** : déposer un export CSV. L'aperçu montre les lignes nouvelles, les doublons ignorés et les lignes à
   catégoriser (en tête). Corriger une catégorie propose de créer une règle et de l'appliquer aux lignes similaires.
2. **Mois** et **Année** : totaux, graphiques en bâtons ou en anneau, tableau (matrice catégories × mois pour l'année).
   Un clic sur une catégorie affiche son détail ; chaque ligne peut être recatégorisée.
3. **À trier** : toutes les lignes encore « À catégoriser » ; le badge du menu en donne le nombre.
4. **Catégories** : ajouter, renommer (partout), supprimer (avec une catégorie de remplacement), changer l'icône.
5. **Règles** : tester un libellé, créer, modifier, supprimer.
6. **Paramètres** : charte graphique (Guimauve, Sauge & Lin, Nuit Lavande).

Avant chaque modification d'un fichier de données, une copie horodatée est gardée dans `data/sauvegardes/`
(les 20 dernières par fichier).

## Tests

```bash
php api/tests/run.php            # tous les tests PHP
php api/tests/run.php CsvParser  # seulement ceux dont le nom contient « CsvParser »
cd front && npm test             # tests Vitest
cd front && npm run typecheck    # vérification TypeScript
```

Les tests qui utilisent `exemple.csv` ou `csv/` (relevés réels, non versionnés) sont marqués « ignorés » s'ils sont absents.

## Organisation

```
api/index.php      routeur de l'API
api/bootstrap.php  chargement automatique des classes
api/src/           classes PHP (namespace Banque\)
api/tests/         tests PHP et leur lanceur maison (run.php)
front/src/         application React (pages/, components/, logique testée dans *.ts)
front/dist/        front construit (npm run build, non versionné)
data/              données : mois/, categories.json, regles.json, parametres.json, sauvegardes/ (non versionné)
docs/              documentation fonctionnelle et technique
router.php         point d'entrée de « php -S »
exemple.csv        extrait de relevé servant aux tests (non versionné)
csv/               relevés réels (non versionné)
```
