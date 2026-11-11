# Mes Comptes — documentation technique

Architecture, formats de données, API et choix d'implémentation. Pour le comportement vu de l'utilisateur, voir
[FONCTIONNEL.md](FONCTIONNEL.md). Le cahier des charges d'origine est [`PROMPT.md`](../PROMPT.md) ; les écarts sont
listés à la fin de ce document.

## Architecture

```
navigateur ──► php -S localhost:8000 router.php
                 ├── /api/*  → api/index.php (routeur, JSON)
                 │              └── api/src/ (classes Banque\) ──► data/ (fichiers JSON)
                 └── reste   → front/dist/ (application React construite, repli sur index.html)

développement : Vite (:5173) sert front/src et relaie /api vers :8000
```

- **Back** : PHP 8.2+, sans framework ni dépendance Composer. `declare(strict_types=1)`, PSR-12, types sur toutes les
  signatures. Chargement automatique des classes par `api/bootstrap.php`.
- **Front** : React 18, TypeScript strict, React Router 7, Recharts, Vite 8. Polices embarquées via `@fontsource`
  (aucune requête externe).
- **Stockage** : fichiers JSON dans `data/`, sans base de données. `data/` et `csv/` sont ignorés par git.
- **Montants** : toujours en **centimes entiers** dans les calculs (PHP et TypeScript), jamais en flottants.

### Classes PHP (`api/src/`)

| Classe | Rôle |
|---|---|
| `Router` | Associe méthode + motif d'URL (`{nom:regex}`) à un gestionnaire ; 404 / 405 |
| `HttpException`, `CsvFormatException`, `LineConflictException` | Erreurs → statut HTTP (voir « Erreurs ») |
| `Storage` | Chemins de `data/`, création des dossiers et des fichiers initiaux |
| `JsonFile` | Lecture, écriture atomique, sauvegardes |
| `CsvParser` → `ParseResult`, `ParsedLine`, `RejectedLine` | Lecture du CSV Crédit Agricole |
| `Amount` | Texte ↔ centimes (`"2 640,31"` → 264031 → `"2640,31"`) |
| `Text` | Espaces, majuscules sans accents |
| `LabelNormalizer` → `NormalizedLabel` | Type d'opération et détail nettoyé d'un libellé |
| `Rule`, `RuleRepository` | Une règle (correspondance, priorité, format JSON) ; lecture/écriture de `regles.json` |
| `Categorizer` → `Categorization` | Moteur de règles et repli par type d'opération |
| `CategoryCatalog` | Catégories existantes (validation) |
| `CategoryManager` | Ajout, icône, renommage et suppression répercutés dans les mois et les règles |
| `DefaultData` | Catégories, règles et paramètres initiaux, liste des thèmes |
| `Direction` | Énumération `dépense` / `revenu` |
| `MonthLine` | Forme JSON d'une ligne, clé de doublon, validation d'une ligne reçue |
| `MonthRepository` | Fichiers `data/mois/YYYYMM.json` : liste, lecture, écriture triée, recatégorisation |
| `Importer` | Aperçu et confirmation d'un import, fusion par occurrences |

### Front (`front/src/`)

| Fichier | Rôle |
|---|---|
| `types.ts` | Types partagés : formats JSON de `data/` et réponses de l'API |
| `api.ts` | Client HTTP ; émet l'événement `mes-comptes:donnees` après chaque écriture (badge « À trier ») |
| `aggregate.ts` | Totaux, regroupements par catégorie, matrice annuelle, ordre chronologique (testés) |
| `importLogic.ts` | Ordre d'affichage et contenu envoyé à la confirmation d'import (testés) |
| `format.ts` | Centimes ↔ texte, format français `1 234,56 €` (testés) |
| `categories.ts`, `colors.ts`, `months.ts`, `operationTypes.ts`, `theme.ts` | Aides d'affichage |
| `useRecategorisation.tsx` | Recatégorisation + fenêtre d'apprentissage, partagée par Mois, Année et À trier |
| `pages/` | Une page par onglet |
| `components/` | Blocs de graphiques, tableaux, matrice, fenêtres, sélecteurs de catégorie |
| `styles.css` | Styles ; les trois chartes sont des jeux de variables CSS sur `[data-theme]` |

## Formats des données (`data/`)

Tous les fichiers sont en UTF-8, écrits avec `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
Les noms de champs comportent des accents.

### `mois/YYYYMM.json`

```json
{
  "mois": "202601",
  "lignes": [
    {
      "date": "12/01/2026",
      "libelle": "Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01",
      "catégorie": { "catégorie1": "Alimentation", "catégorie2": "Courses" },
      "dépense": "2,67"
    },
    {
      "date": "30/01/2026",
      "libelle": "Virement en votre faveur VIREMENT EN VOTRE FAVEUR société X",
      "catégorie": "Salaire",
      "revenu": "2645,05"
    }
  ]
}
```

- Une dépense a une catégorie objet (2 niveaux) et un champ `dépense` ; un revenu une catégorie texte et un champ `revenu`.
- Montants : texte, virgule décimale, toujours 2 décimales, sans séparateur de milliers.
- `libelle` : libellé du CSV, retours à la ligne et espaces multiples remplacés par un espace.
- Lignes triées par date croissante ; à date égale, l'ordre d'arrivée est conservé (tri stable).
- L'**index** d'une ligne (sa position dans `lignes`) sert à la désigner dans l'API.

### `categories.json`

```json
{
  "dépenses": [{ "nom": "Alimentation", "icone": "🛒", "sous_catégories": ["Courses", "Restaurant"] }],
  "revenus": [{ "nom": "Salaire", "icone": "💼" }]
}
```

L'ordre des catégories détermine leur couleur dans les graphiques (position modulo 12 dans la palette de la charte).

### `regles.json`

```json
[
  { "motif": "AUCHAN", "type": "dépense", "catégorie1": "Alimentation", "catégorie2": "Courses", "origine": "initiale" },
  { "motif": "PRET HABITAT", "type": "dépense", "catégorie1": "Banque & assurances", "catégorie2": "Assurance emprunteur",
    "et": ["ASSU"], "origine": "initiale" },
  { "motif": "TOTAL", "type": "dépense", "catégorie1": "Transport", "catégorie2": "Carburant",
    "type_operation": "CARTE", "origine": "initiale" },
  { "motif": "AMAZON", "type": "dépense", "catégorie1": "Shopping", "catégorie2": "Maison & déco",
    "a_confirmer": true, "origine": "initiale" },
  { "motif": "MUTUELLE", "type": "revenu", "catégorie": "Remboursements santé", "origine": "apprise" }
]
```

| Champ | Obligatoire | Description |
|---|---|---|
| `motif` | oui | Mots recherchés (mots entiers, insensible à la casse et aux accents) |
| `type` | oui | `dépense` ou `revenu` |
| `catégorie1` + `catégorie2` / `catégorie` | oui | Selon le sens |
| `origine` | oui | `initiale` ou `apprise` |
| `et` | non | Autres motifs, tous requis |
| `type_operation` | non | Code du type d'opération requis (voir ci-dessous) |
| `a_confirmer` | non | `true` : ligne mise en évidence à l'import |

Codes de type d'opération : `CARTE`, `PRELEVEMENT`, `VIREMENT_RECU`, `VIREMENT_EMIS`, `RETRAIT`, `REGLEMENT`,
`REMISE_CHEQUE`, `CHEQUE`, `FRAIS`, `COTISATION`, `INTERETS`, `REMBOURSEMENT_PRET`, `REJET`, `REGULARISATION`,
`AVOIR`, `AUTRE`.

Le texte comparé aux motifs est `type tel qu'écrit + détail nettoyé` (ex. `PAIEMENT PAR CARTE AUCHAN PEROLS`).
Priorité : apprise > initiale, puis longueur du motif (somme des longueurs de `motif` et `et`), puis ordre du fichier.

### `parametres.json`

```json
{ "thème": "guimauve" }
```

Valeurs : `guimauve`, `sauge`, `nuit`. Le front garde aussi une copie dans `localStorage` (`mes-comptes-theme`) pour
appliquer la charte avant le premier affichage ; `parametres.json` reste la référence.

### `sauvegardes/` et fichiers `.lock`

- `JsonFile::write` : verrou exclusif (`flock` sur `<fichier>.lock`), copie de l'ancien fichier dans
  `sauvegardes/<nom>-<YYYYMMDD-HHMMSS-µs>.json`, écriture dans un fichier temporaire puis `rename` (atomique).
- 20 sauvegardes conservées par fichier. Les fichiers `.lock` restent sur le disque ; ils sont vides et inoffensifs.

### Initialisation

Au premier appel de l'API, `Storage` crée `data/`, `data/mois/`, `data/sauvegardes/` et, s'ils n'existent pas,
`categories.json`, `regles.json` (47 règles initiales) et `parametres.json` à partir de `DefaultData`. Modifier
`DefaultData` n'a pas d'effet sur un `data/` existant.

`BANQUE_DATA_DIR` (variable d'environnement) remplace le dossier `data/` : instance d'essai, tests manuels.

## API

Toutes les réponses sont en JSON. Les erreurs ont la forme `{ "erreur": "message" }`.

| Méthode et route | Corps / paramètres | Réponse |
|---|---|---|
| `GET /api/sante` | | `{ statut, php }` |
| `POST /api/import/preview` | multipart, champ `fichier` (2 Mo max) | aperçu : `résumé`, `mois[].lignes[]` (avec `doublon`, `à_revoir`, `marchand`, `type_operation`, `motif`), `erreurs[]` — **n'écrit rien** |
| `POST /api/import/confirm` | `{ lignes: [{ date, libelle, sens, montant, catégorie }] }` (20 000 max) | `{ ajoutées, mois: [{ mois, ajoutées, doublons }] }` |
| `GET /api/mois` | | `["202504", …]` |
| `GET /api/mois/{YYYYMM}` | | `{ mois, lignes, analyse: [{ type_operation, marchand }] }` |
| `PATCH /api/mois/{YYYYMM}/lignes/{index}` | `{ date, libelle, catégorie, autres?: [{ index, date, libelle }] }` | le mois mis à jour |
| `GET /api/annee/{YYYY}` | | `{ année, mois: [mois au format ci-dessus] }` |
| `GET /api/a-trier` | | `[{ mois, index: [positions], lignes, analyse }]` |
| `GET /api/categories` | | contenu de `categories.json` |
| `PUT /api/categories` | `{ action, sens, chemin, nom?, icone?, sous_catégorie?, remplacement? }` | `{ catégories, modifiés: { lignes, règles } }` |
| `POST /api/categories/usage` | `{ sens, chemin }` | `{ lignes, règles }` |
| `GET /api/regles` | | contenu de `regles.json` |
| `POST /api/regles` | `{ règle, appliquer_aux_mois }` | `{ règle, remplacée, lignes_mises_à_jour }` |
| `POST /api/regles/correspondances` | `{ règle, lignes: [{ libelle, sens }] }` | `{ correspondances: [bool] }` |
| `POST /api/regles/tester` | `{ libelle, sens }` | `{ type_operation, marchand, texte, catégorie, source, règle }` |
| `PUT /api/regles/{index}` | `{ motif_actuel, règle }` | la règle |
| `DELETE /api/regles/{index}?motif=…` | | `{ supprimée }` |
| `GET /api/parametres`, `PUT /api/parametres` | `{ thème }` | les paramètres |

`PUT /api/categories` — actions :

| `action` | Champs | Effet |
|---|---|---|
| `ajouter` | `chemin` `[c1]` + `icone` (+ `sous_catégorie` pour une dépense), ou `[c1, c2]` | Ajout |
| `icone` | `chemin` `[c1]`, `icone` | Changement d'icône |
| `renommer` | `chemin` `[c1]` ou `[c1, c2]`, `nom` | Renommage dans `categories.json`, tous les mois et toutes les règles |
| `supprimer` | `chemin`, `remplacement` (`[revenu]` ou `[c1, c2]`) si la catégorie est utilisée | Lignes et règles déplacées, puis suppression |

### Concurrence et désignation par index

Les lignes et les règles sont désignées par leur index. Pour ne jamais modifier le mauvais élément si les données ont
changé entre l'affichage et l'action :

- `PATCH …/lignes/{index}` vérifie que la date et le libellé de chaque ligne visée correspondent ;
- `PUT` / `DELETE /api/regles/{index}` vérifient le motif actuel.

En cas de différence, rien n'est écrit et l'API répond **409**. La confirmation d'import ne dépend pas des index :
elle reçoit toutes les lignes du CSV et refait la fusion, ce qui la rend rejouable sans doublon.

### Erreurs

| Statut | Cas |
|---|---|
| 400 | Corps JSON absent ou mal formé, fichier manquant, paramètre invalide |
| 404 | Route ou mois inconnu |
| 405 | Méthode non autorisée |
| 409 | Ligne ou règle modifiée entre-temps |
| 413 | Fichier (2 Mo) ou corps (10 Mo) trop volumineux |
| 422 | Données refusées : CSV sans en-tête, catégorie inconnue, montant invalide, règle mal formée… |
| 500 | Erreur interne (message renvoyé et journalisé) |

## Sécurité

- Usage local uniquement, sans authentification : ne pas exposer le serveur sur un réseau.
- Aucun chemin n'est construit à partir d'une saisie non contrôlée : le mois est validé (`YYYYMM`, mois 01–12) avant
  tout accès disque ; `router.php` ne sert que des fichiers situés dans `front/dist`.
- Toutes les entrées sont validées : formats de date et de montant, existence des catégories, sens, types
  d'opération, tailles.
- Écritures atomiques avec verrou ; sauvegarde avant chaque modification.
- Dépendances du front : `npm audit` sans vulnérabilité ; versions exactes (`--save-exact`), choisies publiées depuis
  au moins deux semaines. Le lockfile a été régénéré avec `npm install --before=2026-10-28`.

## Tests

| Commande | Contenu |
|---|---|
| `php api/tests/run.php [filtre]` | Lanceur maison (`api/tests/run.php`, `TestCase.php`) : parseur, normalisation, montants, règles et priorités, catégoriseur, import et doublons par occurrences, apprentissage, mois, catégories, JSON atomique, routeur |
| `cd front && npm test` | Vitest : formatage, agrégations (totaux, regroupements, matrice), logique d'import |

Les tests qui lisent `exemple.csv` ou `csv/` (non versionnés, données réelles) sont marqués **ignorés** si ces
fichiers sont absents. Les autres utilisent des données inventées et des dossiers temporaires.

## Écarts par rapport au cahier des charges (`PROMPT.md`)

Décisions prises pendant la réalisation :

| Sujet | Cahier des charges | Réalisation |
|---|---|---|
| Fichiers multi-comptes | non prévu | Un seul compte par fichier ; nettoyage en amont |
| Libellés sur plusieurs lignes | non prévu | Sauts de ligne remplacés par un espace (aussi pour les doublons) |
| Correspondance des motifs | non précisé | Mots entiers |
| Règles | `motif`, `type`, catégorie, `origine` | + `et`, `type_operation`, `a_confirmer` |
| Règles initiales | tableau du §4 | Ajustements listés dans FONCTIONNEL.md |
| Types d'opération | 11 types | + Virement reçu, Remboursement de prêt, Rejet, Régularisation, Avoir |
| Icônes | catégories 1 et 2 | Catégories 1 seulement (le format de `categories.json` n'en prévoit pas pour les 2) |
| `PUT /api/categories` | remplacement du fichier | Une action par appel, pour répercuter renommages et suppressions |
| Routes supplémentaires | — | `/api/sante`, `/api/a-trier`, `/api/categories/usage`, `/api/regles/correspondances`, `/api/regles/tester` |
| `PATCH` d'une ligne | index | Index + vérification date/libellé (409) ; champ `autres` pour les lignes similaires |
| `api/index.php` | une centaine de lignes | Environ 300 lignes (gestionnaires de routes inclus) |
| Polices | Google Fonts implicite | Embarquées (`@fontsource`) |
| Onglet « À trier » | — | Ajouté, avec badge |
| Tests PHP | non précisé | Lanceur maison, sans PHPUnit |
