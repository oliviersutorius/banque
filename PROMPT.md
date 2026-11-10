# Prompt — Site « Mes Comptes » : analyse personnelle des dépenses et revenus

## Rôle

Tu es un développeur full-stack expérimenté (React + PHP) **et** un analyste bancaire spécialisé dans la gestion des comptes de particuliers. Tu sais lire des relevés bancaires français (Crédit Agricole en particulier), reconnaître la nature d'une opération à partir de son libellé et la classer dans une catégorie pertinente.

Tu vas construire un site web personnel, utilisé **uniquement en local**, qui me donne une vision claire de tous mes postes de dépenses et de revenus, mois par mois et année par année.

**Ne prends pas de décision hâtive.** Si un point de ce cahier des charges est ambigu, contradictoire ou incomplet, arrête-toi et pose-moi la question avant de coder. Avance par étapes et montre-moi le résultat de chaque étape (voir « Plan de réalisation »).

---

## 1. Stack technique

| Couche | Choix | Pourquoi |
|---|---|---|
| Front | **React 18 + Vite + TypeScript**, React Router, **Recharts** pour les graphiques | Interface réactive, graphiques simples à intégrer |
| Back | **PHP 8.2+ sans framework** (un routeur `index.php` d'une centaine de lignes) | Un navigateur ne peut pas écrire de fichiers sur le disque : il faut un petit serveur. Sans base de données, un framework (Laravel, Symfony) serait surdimensionné |
| Stockage | **Fichiers JSON** dans `data/` | Aucune base de données |
| Lancement | `php -S localhost:8000 router.php` (sert l'API et le build du front) ; en dev, Vite sur :5173 avec un proxy `/api` → :8000 | Tout tourne sur ma machine |

Pas d'authentification (usage local). Le dossier `data/` doit être dans `.gitignore` (données bancaires personnelles).

### Arborescence attendue

```
banque/
├── api/
│   ├── index.php            # routeur de l'API
│   ├── src/
│   │   ├── CsvParser.php     # lecture et normalisation du CSV
│   │   ├── Categorizer.php   # application des règles
│   │   ├── MonthRepository.php  # lecture/écriture des JSON mensuels
│   │   └── ...
│   └── tests/               # tests du parseur et du catégoriseur
├── front/                   # application React (Vite)
├── data/                    # créé au premier lancement, ignoré par git
│   ├── mois/202601.json
│   ├── categories.json
│   ├── regles.json
│   ├── parametres.json
│   └── sauvegardes/
├── router.php
├── exemple.csv              # extrait réel anonymisé (voir §2)
└── README.md                # comment installer et lancer
```

---

## 2. Le fichier CSV (Crédit Agricole)

Un extrait réel anonymisé est fourni dans `exemple.csv`. **Calibre le parseur et ses tests sur ce fichier.**

### Caractéristiques observées

- 4 colonnes : `Date;Libellé;Débit euros;Crédit euros`
- Séparateur `;`, libellé entre guillemets `"..."`
- Dates au format `DD/MM/YYYY`
- Montants avec virgule décimale et un nombre variable de décimales : `25`, `69,9`, `2,67`, `400,00`, `2645,05`
- **Nombre de champs variable** : certaines lignes se terminent par un `;` en trop (`...;65,64;;`). Il faut l'ignorer sans erreur.
- Les lignes ne sont **pas triées** et un même fichier mélange **plusieurs mois et plusieurs années** (ex. 01/2026 et 07/2025)
- Fichier en UTF-8 sans BOM

### Robustesse à prévoir (exports Crédit Agricole réels)

- Accepter aussi l'encodage **Windows-1252** (détection puis conversion en UTF-8) et un éventuel BOM
- Ignorer les lignes situées **avant** l'en-tête `Date;Libellé;...` (titre, numéro de compte, solde)
- Ignorer les lignes vides et les lignes de fin (solde, totaux)
- Une ligne sans débit ni crédit, avec les deux colonnes remplies ou avec une date invalide doit être **signalée** à l'écran (numéro de ligne et contenu), jamais ignorée en silence

### Règles métier

- `Débit euros` rempli → **dépense** ; `Crédit euros` rempli → **revenu**
- La date de la ligne détermine le fichier JSON (mois `YYYYMM`)
- Tous les calculs se font en **centimes entiers**, jamais en flottants

### Structure des libellés Crédit Agricole

Un libellé Crédit Agricole se compose du **type d'opération** (en minuscules, puis répété en majuscules) suivi du **détail** :

```
Paiement par carte PAIEMENT PAR CARTE X1234 Spotify France PARIS 11/01
└─ type ──────────┘└─ type répété ─────┘└carte┘└─ marchand ──┘└date d'achat┘
```

Types rencontrés : `Paiement par carte`, `Virement en votre faveur`, `Virement émis`, `Prélèvement`, `Règlement`, `Retrait au distributeur`. Prévois aussi `Remise de chèque`, `Chèque`, `Frais`, `Cotisation`, `Intérêts`.

Implémente une fonction de **normalisation du libellé** qui sert uniquement à la catégorisation (le libellé d'origine est conservé tel quel dans le JSON) :
1. Extraire le type d'opération
2. Supprimer le type répété en majuscules
3. Supprimer le numéro de carte (`X1234`), les dates (`29/01`, `07/25`), les heures (`08H31`) et les longues suites de chiffres (`246709`)
4. Passer en majuscules, retirer les accents, réduire les espaces

Exemple : `Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01` → type `CARTE`, marchand `AUCHAN PEROLS`.

---

## 3. Format des fichiers JSON

### `data/mois/YYYYMM.json` — un fichier par mois

```json
{
  "mois": "202601",
  "lignes": [
    {
      "date": "12/01/2026",
      "libelle": "Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01",
      "catégorie": {
        "catégorie1": "Alimentation",
        "catégorie2": "Courses"
      },
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

Règles :
- Les noms de champs sont **exactement** ceux-ci, avec les accents : `mois`, `lignes`, `date`, `libelle`, `catégorie`, `catégorie1`, `catégorie2`, `dépense`, `revenu`
- Une dépense a une `catégorie` **objet** (2 niveaux), un revenu a une `catégorie` **chaîne** (1 niveau)
- Les montants sont des **chaînes**, avec virgule décimale et **toujours 2 décimales**, sans séparateur de milliers : `"69,90"`, `"25,00"`
- Les lignes sont triées par date croissante. À date égale, l'ordre du CSV est conservé.
- JSON écrit en UTF-8 avec `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
- Écriture **atomique** (fichier temporaire puis `rename`, avec `flock`). Avant chaque modification, une copie horodatée du fichier est faite dans `data/sauvegardes/` (garder les 20 dernières).

### `data/categories.json` — la liste des catégories

```json
{
  "dépenses": [
    { "nom": "Alimentation", "icone": "🛒", "sous_catégories": ["Courses", "Restaurant", "Boulangerie & snacks", "Livraison de repas"] }
  ],
  "revenus": [
    { "nom": "Salaire", "icone": "💼" }
  ]
}
```

### `data/regles.json` — les règles de catégorisation

```json
[
  { "motif": "AUCHAN", "type": "dépense", "catégorie1": "Alimentation", "catégorie2": "Courses", "origine": "initiale" },
  { "motif": "MUTUELLE", "type": "revenu", "catégorie": "Remboursements santé", "origine": "apprise" }
]
```

### `data/parametres.json` — par exemple le thème choisi

---

## 4. Catégorisation

### Catégories initiales (modifiables depuis l'interface)

**Dépenses (niveau 1 → niveau 2)**

| Icône | Niveau 1 | Niveau 2 |
|---|---|---|
| 🏠 | Logement | Loyer, Crédit immobilier, Charges & copropriété, Électricité & gaz, Eau, Assurance habitation, Travaux & entretien |
| 🛒 | Alimentation | Courses, Restaurant, Boulangerie & snacks, Livraison de repas |
| 🚗 | Transport | Carburant, Péage & parking, Transports en commun, Train & avion, Entretien véhicule, Assurance auto |
| 💊 | Santé | Médecin, Pharmacie, Mutuelle, Optique & dentaire |
| 📱 | Abonnements & télécom | Téléphone mobile, Internet, Streaming & musique, Logiciels & presse |
| 🎈 | Loisirs & culture | Sorties, Sport, Voyages & vacances, Livres & jeux, Cadeaux |
| 🛍️ | Shopping | Vêtements, Maison & déco, High-tech, Beauté |
| 👨‍👩‍👧 | Famille | Garde & scolarité, Enfants, Animaux |
| 🏦 | Banque & assurances | Frais bancaires, Assurance emprunteur, Autres assurances, Remboursement de prêt |
| 🧾 | Impôts & taxes | Impôt sur le revenu, Taxe foncière, Autres taxes & amendes |
| 🐷 | Épargne & virements | Assurance vie, Livrets, Virement vers un autre compte |
| 💶 | Retraits d'espèces | Distributeur |
| ❔ | À catégoriser | Non classé |

**Revenus (1 niveau)** : 💼 Salaire · 🩺 Remboursements santé · 🤝 Allocations & aides · 🔁 Virements reçus · 📈 Revenus d'épargne · ✨ Autres revenus · ❔ À catégoriser

### Règles initiales (`origine: "initiale"`)

Ces règles s'appliquent au libellé normalisé, avec le type de ligne (dépense ou revenu).

| Motif | Type | Catégorie |
|---|---|---|
| AUCHAN, CARREFOUR, LECLERC, INTERMARCHE, LIDL, ALDI, MONOPRIX, CASINO, SUPER U, FRANPRIX, PICARD | dépense | Alimentation › Courses |
| SPOTIFY, NETFLIX, DEEZER, DISNEY, CANAL | dépense | Abonnements & télécom › Streaming & musique |
| FREE MOBILE, ORANGE, SFR, BOUYGUES, SOSH | dépense | Abonnements & télécom › Téléphone mobile |
| PREDICA, ASSURANCE VIE | dépense | Épargne & virements › Assurance vie |
| ASSU. CNP, PRET HABITAT (avec ASSU) | dépense | Banque & assurances › Assurance emprunteur |
| COTISATION, FRAIS, AGIOS | dépense | Banque & assurances › Frais bancaires |
| DGFIP, IMPOT | dépense | Impôts & taxes › Impôt sur le revenu |
| EDF, ENGIE, TOTALENERGIES ELEC | dépense | Logement › Électricité & gaz |
| TOTAL, ESSO, BP, SHELL, AVIA | dépense (carte) | Transport › Carburant |
| SNCF, OUIGO, AIR FRANCE | dépense | Transport › Train & avion |
| PHARMACIE | dépense | Santé › Pharmacie |
| AMAZON | dépense | Shopping › Maison & déco (à confirmer à chaque fois) |
| MUTUELLE, CPAM | revenu | Remboursements santé |
| CAF | revenu | Allocations & aides |

**Règles par type d'opération** (appliquées seulement si aucun motif ne correspond) :
- `Retrait au distributeur` → Retraits d'espèces › Distributeur
- `Virement émis` → Épargne & virements › Virement vers un autre compte
- `Virement en votre faveur` → Virements reçus
- Tout le reste → **À catégoriser**

Ne crée **pas** de règle pour mon salaire : le nom de l'employeur est anonymisé dans l'exemple. Je la créerai au premier import en corrigeant la ligne.

### Moteur de règles

1. Les règles `apprises` passent avant les règles `initiales`
2. À priorité égale, le **motif le plus long** gagne (plus spécifique)
3. Une règle ne s'applique qu'au type de ligne (dépense ou revenu) qui lui correspond
4. Les catégories existantes sont **toujours réutilisées**. Une catégorie n'est jamais inventée automatiquement : sans règle, la ligne va dans « À catégoriser ».

### Apprentissage

Quand je corrige la catégorie d'une ligne, une fenêtre me propose :
- de **créer une règle**, avec un motif pré-rempli à partir du libellé normalisé (ex. `AUCHAN PEROLS` → je peux le raccourcir en `AUCHAN`) ;
- d'**appliquer la correction aux lignes similaires** : celles du lot en cours d'import, et en option celles des mois déjà enregistrés qui sont encore « À catégoriser ».

Les règles apprises sont enregistrées dans `regles.json` et appliquées à tous les imports suivants. Une page « Règles » permet de les lister, de les modifier, de les supprimer et de tester un libellé.

---

## 5. Fonctionnalités

### 5.1 Import d'un CSV (2 étapes)

1. **Prévisualisation** (`POST /api/import/preview`) : je dépose le fichier (glisser-déposer ou bouton). Le serveur le lit et le catégorise, mais **n'écrit rien**. L'écran affiche :
   - le résumé : nombre de lignes, mois concernés, dépenses et revenus, lignes en erreur ;
   - par mois : les lignes **nouvelles** et les **doublons** ignorés (grisés) ;
   - les lignes « À catégoriser » en tête et mises en évidence, pour que je les corrige directement (avec l'apprentissage du §4).
2. **Confirmation** (`POST /api/import/confirm`) : le serveur écrit ou complète les JSON mensuels.

**Doublons et fusion** : une ligne est un doublon si un autre élément a la même **date + libellé + sens + montant**. La comparaison se fait **par nombre d'occurrences** : si le CSV contient deux fois le même café du même jour et que le JSON n'en contient qu'un, on en ajoute un. Les catégories des lignes déjà enregistrées ne sont **jamais écrasées** par un import.

### 5.2 Vue mensuelle

- Un sélecteur de mois (flèches précédent/suivant et liste des mois disponibles)
- Des cartes de synthèse : **total des dépenses**, **total des revenus**, **solde** du mois
- Deux blocs côte à côte (empilés sur mobile) : **Dépenses par catégorie 1** et **Revenus par catégorie**
- Pour chaque bloc, un commutateur entre 3 affichages :
  - **Bâtons** : une barre par catégorie, triées par montant décroissant
  - **Camembert** (en anneau) : avec le pourcentage et le montant au survol
  - **Tableau** : les lignes du mois **triées par ordre chronologique** (date, libellé, catégorie 1 › catégorie 2, montant), avec un sous-total par catégorie 1 en pied de tableau
- Un clic sur une catégorie 1 (barre, part du camembert ou ligne de sous-total) affiche le **détail par catégorie 2** et la liste des lignes concernées
- Chaque ligne peut être **recatégorisée** à la main (`PATCH /api/mois/{YYYYMM}/lignes/{index}`), avec l'apprentissage du §4

### 5.3 Récapitulatif annuel

- Un sélecteur d'année, les mêmes cartes de synthèse et les mêmes 3 affichages, **calculés sur l'année entière**
- En vue **Tableau** : une matrice **catégories (lignes) × mois de janvier à décembre (colonnes)**, avec les totaux par ligne et par colonne. Un mois sans fichier affiche « — ».
- Le même clic sur une catégorie 1 donne le détail par catégorie 2 sur l'année

### 5.4 Gestion des catégories

- Ajouter, renommer, supprimer et changer l'icône des catégories 1 et 2 (dépenses) et des catégories de revenus
- **Renommer** une catégorie met à jour **tous** les fichiers mensuels et toutes les règles (après confirmation, avec le nombre de lignes impactées)
- **Supprimer** une catégorie utilisée oblige à choisir une catégorie de remplacement
- « À catégoriser » ne peut pas être supprimée

### 5.5 Paramètres

- Le choix de la charte graphique (§6), enregistré dans `parametres.json`

### 5.6 API (récapitulatif)

```
POST   /api/import/preview          multipart CSV → lignes analysées (rien n'est écrit)
POST   /api/import/confirm          lignes validées → écriture des JSON
GET    /api/mois                    liste des mois disponibles
GET    /api/mois/{YYYYMM}           contenu d'un mois
PATCH  /api/mois/{YYYYMM}/lignes/{index}   recatégoriser une ligne
GET    /api/annee/{YYYY}            les mois de l'année (agrégation côté front)
GET/PUT /api/categories
GET/POST/PUT/DELETE /api/regles
GET/PUT /api/parametres
```

Valide toutes les entrées : le format `YYYYMM`, l'existence des catégories, la taille du fichier, et aucun chemin construit à partir d'une saisie sans contrôle (pas de `../`).

---

## 6. Design : simple, épuré et mignon

Principes communs : beaucoup d'espace blanc, des coins arrondis généreux, des ombres très douces, une icône emoji par catégorie, des chiffres bien lisibles (`font-variant-numeric: tabular-nums`) et des montants au format français (`1 234,56 €`). Les dépenses et les revenus ont chacun une couleur fixe. « À catégoriser » est toujours en gris. Le site doit être responsive.

Les 3 chartes sont implémentées avec des **variables CSS** sur `[data-theme="..."]` et un **sélecteur de thème** dans les paramètres. Chaque charte fournit une palette de 12 couleurs pour les catégories des graphiques, attribuées de façon stable (une catégorie garde toujours la même couleur).

### Charte 1 — « Guimauve » (pastel, par défaut)
- Fond `#FFF8F5`, cartes `#FFFFFF`, texte `#3D3346`, texte secondaire `#8A7F91`
- Accent `#F49AC1` (rose), dépenses `#F4A3A8` (corail doux), revenus `#8FD3B6` (menthe)
- Polices : **Quicksand** (titres) et **Nunito** (texte)
- Coins de 20 px, ombre `0 4px 16px rgba(244,154,193,.15)`, boutons en pilule
- Palette : `#F4A3A8 #F7C59F #F9E79F #B5E3A1 #8FD3B6 #A8DADC #9CC5F0 #B8A4F4 #D7A6E8 #F49AC1 #C9B79C #BFC4CC`

### Charte 2 — « Sauge & Lin » (naturel, apaisant)
- Fond `#F6F3EC`, cartes `#FFFDF8`, texte `#2F3B33`, texte secondaire `#7A8478`
- Accent `#7FA37A` (sauge), dépenses `#C98A6B` (terracotta), revenus `#7FA37A`
- Polices : **Fraunces** (titres) et **DM Sans** (texte)
- Coins de 14 px, bordure fine `1px solid #E6E0D3` plutôt que des ombres
- Palette : `#7FA37A #C98A6B #D9B38C #8BA9B8 #B5838D #A3B18A #E0C097 #6D8B74 #C7A27C #9E8FB2 #D4A5A5 #A7A9A0`

### Charte 3 — « Nuit Lavande » (sombre, douce)
- Fond `#1E1B2E`, cartes `#2A2640`, texte `#ECE8F7`, texte secondaire `#A79FC2`
- Accent `#B8A4F4` (lavande), dépenses `#F59EBB` (rose), revenus `#86E3C3` (menthe)
- Polices : **Nunito** (titres et texte)
- Coins de 18 px, légère lueur `0 0 0 1px rgba(184,164,244,.12)`
- Palette : `#B8A4F4 #F59EBB #86E3C3 #FFD38A #8CC8FF #FFAB91 #C5E68A #E4A6F2 #7FDBDA #F7E08A #A0A8C8 #D9B8A0`

Petites touches mignonnes, mais discrètes : un état vide illustré (« Aucun mois importé pour l'instant 🐣 — dépose ton premier CSV »), une animation douce à l'apparition des graphiques et un message encourageant quand le solde du mois est positif.

---

## 7. Qualité

- PHP : `declare(strict_types=1);`, PSR-12, types sur toutes les signatures, exceptions explicites, aucun `var_dump` ni `print_r` oublié
- TypeScript strict côté front, des types partagés pour le format JSON
- **Tests** : le parseur (sur `exemple.csv` : 10 lignes de données — `202601` : 5 dépenses et 1 revenu, `202507` : 3 dépenses et 1 revenu, montants normalisés `"69,90"`, `";"` final toléré), la normalisation des libellés, le moteur de règles (priorités) et la fusion des doublons (cas des occurrences multiples). Plus quelques tests d'agrégation côté front (Vitest).
- Un `README.md` qui explique l'installation et le lancement en local

---

## 8. Plan de réalisation (avance étape par étape et montre-moi chaque étape)

1. Squelette du projet, routeur PHP, Vite et proxy, `README`
2. Parseur CSV, normalisation des libellés et leurs tests sur `exemple.csv`
3. Catégories et règles initiales, moteur de règles et tests
4. Import en 2 étapes (prévisualisation, fusion sans doublons, confirmation) et écriture des JSON
5. Vue mensuelle (3 affichages, détail par catégorie 2, recatégorisation et apprentissage)
6. Récapitulatif annuel
7. Pages Catégories, Règles et Paramètres
8. Les 3 chartes graphiques et le sélecteur de thème, finitions responsive

Avant de commencer, reformule en quelques lignes ce que tu as compris et liste tes questions éventuelles.
