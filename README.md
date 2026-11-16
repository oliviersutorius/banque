# Mes Comptes

Site personnel, utilisé en local ou en ligne (un seul compte, avec double authentification), pour analyser ses dépenses et revenus à partir des exports CSV du Crédit Agricole.

- **Front** : React 18 + Vite + TypeScript, React Router, Recharts (`front/`), polices embarquées (aucune requête externe)
- **API** : PHP 8.5+ sans framework (`api/`)
- **Stockage** : base **SQLite** `base/banque.sqlite`, créée au premier lancement et **ignorée par git** (données
  bancaires personnelles) ; journaux dans `log/`

Documentation :

- [docs/FONCTIONNEL.md](docs/FONCTIONNEL.md) — utilisation, import et doublons, règles et apprentissage, catégories
- [docs/TECHNIQUE.md](docs/TECHNIQUE.md) — architecture, schéma de la base, API, sécurité, migration, mise en ligne,
  tests, écarts au cahier des charges
- [docs/AUDIT_SECURITE.md](docs/AUDIT_SECURITE.md) — audit de sécurité, montées de version, étapes pour une mise en ligne
- [PROMPT.md](PROMPT.md) — cahier des charges d'origine

## Prérequis

- PHP 8.5 ou plus récent, avec les extensions `mbstring`, `sodium` (chiffrement du secret de double authentification)
  et `pdo_sqlite` (`sudo apt install php8.5-sqlite3`)
- Node.js **24 LTS** et npm (version dans `front/.nvmrc` : `nvm use` dans `front/`)

Ou seulement **Docker** (avec Docker Compose), sous Linux : voir [Lancer avec Docker](#lancer-avec-docker). PHP reste
utile sur le poste pour les scripts `api/bin/`, sinon ils se lancent aussi dans le conteneur.

## Installation

```bash
cd front
npm install
```

## Données d'avant novembre 2026 : migration vers la base

Si le dossier `data/` existe encore (anciens fichiers JSON), le site répond « Migration nécessaire » tant qu'ils ne
sont pas transférés dans la base :

```bash
php api/bin/migrer-json.php               # 1. simulation : rapport, rien n'est écrit
php api/bin/migrer-json.php --appliquer   # 2. crée base/banque.sqlite et vérifie qu'elle est identique aux fichiers
php api/bin/migrer-json.php --nettoyer    # 3. archive data/ (base/archives/*.tar.gz), puis supprime les fichiers migrés
```

Détails : [TECHNIQUE.md](docs/TECHNIQUE.md#migration-depuis-les-fichiers-json-data).

## Premier lancement : créer le compte

Le site exige une connexion. Créer le compte une fois, en ligne de commande. Sur le poste local, le plus simple est
un compte sans double authentification :

```bash
php api/bin/compte.php init --sans-totp
```

Pour un compte avec double authentification (obligatoire en ligne) : générer d'abord une clé de chiffrement, puis
`init` sans option. Le script affiche la clé à saisir dans l'application d'authentification et 10 codes de secours.

```bash
php api/bin/cle.php ~/.config/banque-cle          # une seule fois ; ne jamais régénérer ensuite
BANQUE_CLE_FICHIER=~/.config/banque-cle php api/bin/compte.php init
```

Autres commandes : `mot-de-passe`, `totp-reset`, `debloquer` (voir [FONCTIONNEL.md](docs/FONCTIONNEL.md#connexion)).

## Lancement

Avec un compte créé par `--sans-totp`, le serveur doit tourner avec `BANQUE_TOTP=desactive` : le mot de passe
suffit. Le plus simple est de l'écrire une fois pour toutes dans `.env.local` (ignoré par git), lu au démarrage par
`router.php` :

```bash
cp .env.local.example .env.local
```

Ce réglage ne fonctionne que sur `localhost` en HTTP depuis la machine elle-même ; il n'a aucun effet en ligne.
Avec un compte à double authentification, mettre plutôt `BANQUE_CLE_FICHIER=/home/<vous>/.config/banque-cle` dans
`.env.local`. Une variable passée dans la commande (`BANQUE_TOTP=… php -S …`) reste prioritaire.

`.env.local` ne concerne que le serveur : les scripts `api/bin/` ne le lisent pas (passer `BANQUE_CLE_FICHIER=…` ou
`BANQUE_BASE=…` devant la commande).

### Utilisation courante

Construire le front une fois (et après chaque mise à jour du code), puis lancer le serveur PHP, qui sert à la fois
l'API et le front :

```bash
cd front && npm run build && cd .. && php -S localhost:8000 router.php
```

Ouvrir <http://localhost:8000>.

### Lancer avec Docker

L'image construit le front et lance `php -S` avec `router.php`, sans Node ni PHP à installer sur le poste. Une seule
commande, depuis la racine du projet :

```bash
docker compose up --build
```

Ouvrir <http://localhost:8000> ; `Ctrl+C` arrête le serveur. `--build` reconstruit l'image si le code a changé
(rapide sinon, grâce au cache).

Raccourci **`dcu`** : à ajouter une fois à la fin de `~/.zshrc` (ou `~/.bashrc`), puis ouvrir un nouveau terminal ou
lancer `source ~/.zshrc` :

```bash
alias dcu='docker compose up --build'
```

`dcu` se lance ensuite depuis la racine du projet (là où se trouve `compose.yaml`).

Fonctionnement (`compose.yaml`) :

- **Mêmes données qu'hors Docker** : `base/` et `log/` du projet sont montés dans le conteneur, qui tourne avec
  l'UID/GID 1000 (`export UID GID` avant la commande si les vôtres sont différents) : les fichiers restent à vous.
- **`.env.local`** est lu s'il existe (mêmes variables `BANQUE_*`). Les chemins qu'il désigne doivent exister dans le
  conteneur : pour `BANQUE_CLE_FICHIER`, monter le fichier dans un `compose.override.yaml` (non versionné, lu
  automatiquement par Docker Compose) ; `BANQUE_BASE` et `BANQUE_LOG` se règlent avec des chemins du conteneur.

  ```yaml
  services:
    banque:
      volumes:
        - /home/<vous>/.config/banque-cle:/home/<vous>/.config/banque-cle:ro
  ```

- **Réseau de l'hôte** (`network_mode: host`, donc Linux uniquement) : le serveur écoute sur `127.0.0.1:8000` de la
  machine, comme `php -S localhost:8000`. Il n'est pas joignable depuis le réseau local, et l'API reconnaît le
  « poste local » : `BANQUE_TOTP=desactive` fonctionne. Avec des ports publiés (`-p`), les requêtes viendraient de la
  passerelle Docker et la double authentification serait exigée.
- Le port 8000 doit être libre : arrêter un `php -S localhost:8000` déjà lancé.

Scripts et tests dans le conteneur (même base, même `.env.local`) :

```bash
docker compose run --rm banque php api/bin/compte.php mot-de-passe
docker compose run --rm banque php api/bin/base.php sauvegarder
docker compose run --rm banque php api/tests/run.php
```

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

Les variables `BANQUE_BASE` (fichier SQLite) et `BANQUE_LOG` (journaux) font travailler l'API sur une autre base que
`base/banque.sqlite`. Elle sert le front construit (`npm run build`) :

```bash
export BANQUE_BASE=/tmp/banque-essai/banque.sqlite BANQUE_LOG=/tmp/banque-essai/log

# Optionnel : partir d'une copie de ses vraies données (sinon : base neuve avec les catégories et règles initiales)
mkdir -p /tmp/banque-essai && cp "$(ls -1 base/sauvegardes/*.sqlite | tail -1)" /tmp/banque-essai/banque.sqlite

# Un compte pour cette instance (remplace celui de la copie)
php api/bin/compte.php init --sans-totp

BANQUE_TOTP=desactive php -S localhost:8001 router.php
```

Ouvrir <http://localhost:8001>. Pour recommencer : arrêter le serveur et supprimer `/tmp/banque-essai`.
`php api/bin/base.php sauvegarder` fait une sauvegarde à jour avant la copie.

Pour un simple aperçu d'un fichier, la page Import suffit : rien n'est enregistré avant « Importer », sauf si l'on
crée une règle dans la fenêtre d'apprentissage (voir [FONCTIONNEL.md](docs/FONCTIONNEL.md#les-deux-temps-de-limport)).

## Mise en ligne

Sur un VPS, derrière **Caddy** (HTTPS automatique) et **PHP-FPM 8.5**, jamais avec `php -S`. Les fichiers d'exemple
sont dans `deploy/` (Caddyfile, pool PHP-FPM, rotation des journaux) ; la procédure complète est dans
[TECHNIQUE.md](docs/TECHNIQUE.md#mise-en-ligne). En ligne, la double authentification est toujours exigée.

## Utilisation en bref

1. **Import** : déposer un export CSV. L'aperçu montre les lignes nouvelles, les doublons ignorés et les lignes à
   catégoriser (en tête). Corriger une catégorie propose de créer une règle et de l'appliquer aux lignes similaires.
2. **Mois** et **Année** : totaux, graphiques en bâtons ou en anneau, tableau (matrice catégories × mois pour l'année).
   Un clic sur une catégorie affiche son détail ; chaque ligne peut être recatégorisée.
3. **À trier** : toutes les lignes encore « À catégoriser » ; le badge du menu en donne le nombre.
4. **Catégories** : ajouter, renommer (partout), supprimer (avec une catégorie de remplacement), changer l'icône.
5. **Règles** : tester un libellé, créer, modifier, supprimer.
6. **Paramètres** : charte graphique (Guimauve, Sauge & Lin, Nuit Lavande).

Une copie de la base est gardée chaque jour avant la première modification, dans `base/sauvegardes/` (les 20
dernières) ; `php api/bin/base.php sauvegarder | verifier | optimiser` pour l'entretien.

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
api/bin/           scripts : compte.php (compte, mot de passe, TOTP), cle.php, base.php (sauvegarde, vérification),
                   migrer-json.php (ancien data/ → base)
api/schema/        schéma SQL de la base et ses évolutions (001_initial.sql…)
api/bootstrap.php  chargement automatique des classes
api/src/           classes PHP (namespace Banque\)
api/tests/         tests PHP et leur lanceur maison (run.php)
front/src/         application React (pages/, components/, logique testée dans *.ts)
front/dist/        front construit (npm run build, non versionné)
base/              banque.sqlite, sauvegardes/, archives/ (non versionné)
log/               connexions.log (non versionné)
data/              anciens fichiers JSON, jusqu'à la migration (non versionné)
docs/              documentation fonctionnelle et technique
deploy/            exemples de configuration pour la mise en ligne (Caddy, PHP-FPM, logrotate)
router.php         point d'entrée de « php -S » ; lit .env.local
Dockerfile         image locale : build du front, puis php -S (voir « Lancer avec Docker »)
compose.yaml       lancement avec Docker (docker compose up --build, alias dcu)
.env.local.example modèle de .env.local (variables du poste local, non versionné)
exemple.csv        extrait de relevé servant aux tests (non versionné)
csv/               relevés réels (non versionné)
```
