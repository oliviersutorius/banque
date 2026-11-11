# Audit de sécurité — Mes Comptes

Date : 11 novembre 2026 — périmètre : `api/`, `router.php`, `front/`, environnement local (PHP 8.2.35, Node 22.12.0,
npm 10.9.0).

**Suivi** — 11/11/2026 : failles **V1 (CSRF) et V2 (DNS rebinding) corrigées** ; mises à jour de PHP (8.5), Node
(24 LTS), React 19, Recharts 3 et TypeScript 7 faites ; Dependabot et CI GitHub Actions en place (détail au §2, état au
§4). Restent : les failles V3 à V9, et les trois paquets de build qui attendent le délai de 14 jours.

## Synthèse

| Contexte | Verdict |
|---|---|
| Usage actuel (local, `php -S localhost`) | **Correct** ; les deux failles exploitables depuis le navigateur (CSRF, DNS rebinding) sont corrigées depuis le 11/11/2026 |
| Mise en ligne avec authentification | **Pas prêt** : l'architecture actuelle n'a ni identité, ni session, ni cloisonnement des données, ni durcissement HTTP |

Points forts constatés : typage strict partout, validation systématique des entrées (mois `YYYYMM`, dates, montants,
sens, catégories, tailles), aucun chemin de fichier construit à partir d'une saisie libre, motifs de règles échappés
avec `preg_quote` (pas de ReDoS), écritures atomiques avec verrou et sauvegardes, aucune `eval`/`exec`/
`unserialize`, aucun `dangerouslySetInnerHTML` côté React, `npm audit` : **0 vulnérabilité**, dossier `data/` ignoré
par git.

## 1. Vulnérabilités

Gravité évaluée pour l'usage **actuel** ; la colonne « En ligne » indique la gravité une fois exposé.

| # | Constat | Actuel | En ligne | État |
|---|---|---|---|---|
| V1 | CSRF sur les routes `POST` | Moyenne | Critique | ✅ Corrigé le 11/11/2026 |
| V2 | DNS rebinding : aucun contrôle de l'en-tête `Host` | Moyenne | — (remplacé par V5) | ✅ Corrigé le 11/11/2026 |
| V3 | Messages d'exception internes renvoyés au client (500) | Faible | Moyenne | À faire |
| V4 | Aucun en-tête de sécurité HTTP | Faible | Élevée | À faire |
| V5 | Aucune authentification ni autorisation | Nulle (local) | **Critique** | À faire avant mise en ligne |
| V6 | Serveur intégré `php -S` | Nulle | **Critique** | À faire avant mise en ligne |
| V7 | Données bancaires stockées en clair | Faible | Élevée | À faire avant mise en ligne |
| V8 | Aucune limitation de débit | Nulle | Moyenne | À faire avant mise en ligne |
| V9 | Permissions des fichiers de données en 0664 | Faible | Moyenne | À faire |

### V1 — CSRF sur les routes `POST`

`jsonBody()` (`api/index.php`) décode le corps sans vérifier le `Content-Type`. Un site malveillant ouvert dans le même
navigateur peut donc envoyer une requête « simple » (sans pré-vérification CORS) :

```html
<form action="http://localhost:8000/api/import/confirm" method="POST" enctype="text/plain">
  <input name='{"lignes":[{"date":"01/01/2026","libelle":"X","montant":"999,00","sens":"dépense","catégorie":...},"x":"' value='"}'>
</form>
```

ou un `fetch(..., { mode: 'no-cors', headers: { 'Content-Type': 'text/plain' } })`. Routes concernées :
`/api/import/confirm` (injection de fausses opérations), `/api/regles` (ajout de règles et recatégorisation massive),
`/api/import/preview` (multipart, sans effet). Les `PUT`/`PATCH`/`DELETE` sont protégés par la pré-vérification CORS.

**Correctif** : refuser toute requête d'écriture dont le `Content-Type` n'est pas `application/json` (sauf
`multipart/form-data` pour l'aperçu d'import), et vérifier l'en-tête `Origin` (ou `Sec-Fetch-Site: same-origin`)
pour toutes les méthodes autres que `GET`. En ligne, ajouter un cookie de session `SameSite=Strict` ou `Lax` + jeton
CSRF (double soumission).

**✅ Corrigé le 11/11/2026** — classe `api/src/RequestGuard.php`, appelée dans `api/index.php` avant toute route :

- toute écriture (`POST`, `PUT`, `PATCH`, `DELETE`) dont l'en-tête `Origin` ne correspond pas à l'hôte de la requête,
  ou dont `Sec-Fetch-Site` vaut `cross-site` ou `same-site`, est refusée (**403**) ;
- `jsonBody()` exige `Content-Type: application/json` (**415** sinon) : un autre site ne peut envoyer ce type qu'après
  une pré-vérification CORS, que l'API refuse puisqu'elle n'envoie aucun en-tête CORS. Cela couvre aussi les vieux
  navigateurs qui n'envoient pas `Origin` ;
- l'aperçu d'import (`multipart/form-data`) reste accepté, mais seulement depuis la même origine.

Vérification : faille reproduite avant correctif (une requête `text/plain` venue d'un autre site écrivait bien une règle
dans `regles.json`), puis rejouée après : 403 ou 415, rien n'est écrit. Les usages normaux passent : front servi par
`router.php`, front Vite via son proxy (`localhost:5173`), `curl` sans `Origin`, changement de thème depuis Chrome.
Tests : `api/tests/RequestGuardTest.php` (7 tests). Le jeton CSRF reste à ajouter avec l'authentification (étape 5).

### V2 — DNS rebinding

Le serveur répond quel que soit l'en-tête `Host`. Une page dont le domaine est réaffecté vers `127.0.0.1` peut lire
toute l'API (`/api/annee/2025`, `/api/mois`…) en « même origine ».

**Correctif** : dans `router.php`, refuser (421/403) toute requête dont `Host` n'est pas `localhost:8000`,
`127.0.0.1:8000` ou `[::1]:8000`. En ligne : liste blanche du nom de domaine dans le serveur web.

**✅ Corrigé le 11/11/2026** — même classe `RequestGuard` : l'API répond **403** à tout hôte autre que `localhost`,
`127.0.0.1` ou `[::1]`. Le port n'est pas contrôlé, pour garder `:8000`, `:8001` et le proxy Vite `:5173`. Le contrôle
est fait dans `api/index.php` plutôt que dans `router.php`, pour s'appliquer aussi derrière un vrai serveur web (PHP-FPM).
Les fichiers du front restent servis quel que soit l'hôte : ils sont publics et ne contiennent aucune donnée. La
variable d'environnement `BANQUE_HOTES` (noms séparés par des virgules) permettra d'ajouter le nom de domaine lors
d'une mise en ligne. Vérification : `Host: rebinding.example:8002` → 403 sur `/api/regles` et `/api/mois`.

### V3 — Fuite d'informations dans les erreurs 500

```php
sendJson(500, ['erreur' => 'Erreur interne : ' . $e->getMessage()]);
```

Les messages peuvent contenir des chemins ou des détails internes. `/api/sante` expose aussi `PHP_VERSION`.

**Correctif** : message générique + identifiant d'erreur journalisé (`error_log`), et retirer la version de PHP de
`/api/sante` (ou la réserver au mode développement). En production : `display_errors=Off`, `expose_php=Off`.

### V4 — En-têtes de sécurité absents

Ni `router.php` ni l'API n'envoient : `Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy`, `X-Frame-Options`/`frame-ancestors`, `Strict-Transport-Security`, `Permissions-Policy`.

**Correctif** (à poser dans le serveur web en ligne) :

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-<hash du script de thème>'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'
Strict-Transport-Security: max-age=63072000; includeSubDomains
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=()
Cross-Origin-Opener-Policy: same-origin
```

Le script en ligne de `front/index.html` (thème) impose soit un hash CSP, soit son déplacement dans un fichier `.js`
(plus simple). `style-src 'unsafe-inline'` est nécessaire pour Recharts ; à garder tant qu'il est utilisé.

### V5 — Aucune authentification

Toute l'API est ouverte. C'est voulu en local (documenté dans `TECHNIQUE.md`), mais rédhibitoire en ligne. Voir §3.

### V6 — Serveur intégré PHP

`php -S` est mono-processus, sans TLS, non prévu pour la production (documentation PHP). **Correctif** : Nginx ou
Caddy + PHP-FPM (voir §3).

### V7 — Données en clair

Les fichiers `data/mois/*.json` et `data/sauvegardes/` contiennent l'historique bancaire en clair. **Correctif** en
ligne : chiffrement du disque/volume (LUKS ou chiffrement fourni par l'hébergeur), sauvegardes externes chiffrées
(`restic`, `borg`), et idéalement `data/` hors de la racine web.

### V8 — Pas de limitation de débit

Sans objet en local. En ligne : limiter la connexion (ex. 5 essais / 15 min / IP + compte) et l'API globale
(`limit_req` Nginx ou `rate_limit` Caddy). L'import (`/api/import/*`, jusqu'à 20 000 lignes et 10 Mo) est la route la
plus coûteuse.

### V9 — Permissions des fichiers

Les dossiers sont créés en `0700` (`Storage::ensureDirectories`), mais les fichiers héritent de l'umask (`0664`
constaté). **Correctif** : `umask(0077)` au démarrage de l'API, ou `chmod 0600` après écriture dans `JsonFile`.

## 2. Stack technique et montées de version

### Environnement d'exécution

| Outil | Version | État au 11/11/2026 | Action |
|---|---|---|---|
| PHP | 8.2.35 → **8.5.12** | 8.2 : sécurité uniquement jusqu'au 31/12/2026 ; 8.5 : sécurité jusqu'à fin 2029 | ✅ Fait (dépôt Ondřej Surý) : lint sans avertissement, 78 tests réussis, routes de l'API vérifiées ; la CI tourne aussi en 8.5 |
| Node.js | 22.12.0 → **24.22.2** | 24 LTS, maintenue jusqu'à avril 2028 | ✅ Fait (`front/.nvmrc`, `engines`) |
| npm | 10.9.0 → **11.19.1** | Fourni avec Node 24 | ✅ Fait |

Node ne sert qu'à **construire** le front (il ne tourne pas en production), son exposition est donc limitée ; PHP, lui,
est exposé directement.

### Dépendances du front (`npm outdated`)

`npm audit` : 0 vulnérabilité connue.

| Paquet | Avant | Après (11/11/2026) | État |
|---|---|---|---|
| react, react-dom | 18.3.1 | 19.3.1 | ✅ Fait |
| @types/react, @types/react-dom | 18.x | 19.3.0 | ✅ Fait |
| recharts | 2.15.0 | 3.10.1 (+ `react-is` 19.3.1 explicite) | ✅ Fait : clic par rang, contour de focus à la souris masqué |
| typescript | 5.6.3 | 7.0.2 | ✅ Fait |
| vite | 8.3.5 | 8.3.5 | ⏳ 8.3.8 publiée le 11/11 : Dependabot la proposera après le délai de 14 jours |
| @vitejs/plugin-react | 6.1.2 | 6.1.2 | ⏳ 6.3.0 publiée le 09/11 : idem |
| vitest | 5.0.5 | 5.0.5 | ⏳ 5.1.0 publiée le 02/11 : idem |
| react-router-dom | 7.18.5 | 7.18.5 | À jour |

Règle conservée : versions exactes, publiées depuis au moins deux semaines, `npm audit` après chaque passage, tests
(`npm test`, `php api/tests/run.php`) et vérification manuelle des graphiques. Exception : une version plus récente
est installée sans attendre si `npm audit` signale une faille sur la version en place.

### Automatisation

- ✅ **Dependabot** (`.github/dependabot.yml`) : npm et GitHub Actions, chaque semaine, délai de 14 jours
  (`cooldown`), correctifs et mineures regroupés, majeures dans des PR séparées.
- ✅ **CI** (`.github/workflows/ci.yml`) : lint et tests PHP 8.5, tests, build et `npm audit --audit-level=high` du
  front, à chaque push, à chaque PR et chaque lundi ; actions épinglées par SHA.
- Côté serveur : mises à jour de sécurité automatiques de l'OS (`unattended-upgrades`) et dépôt PHP maintenu
  (paquets de la distribution ou dépôt Ondřej Surý / Remi).

## 3. Mise en ligne avec authentification : étapes

Les étapes sont classées par ordre ; les étapes 1 à 6 sont **indispensables** avant toute exposition.

### Étape 1 — Choisir le modèle d'utilisateurs

La réponse change beaucoup le travail :

- **Mono-utilisateur** (vous seul) : un seul compte, données inchangées dans `data/`. Effort modéré.
- **Multi-utilisateurs** : chaque requête doit être cloisonnée par utilisateur (`data/<id>/...` ou base de données),
  avec tests d'accès croisés. Le stockage JSON devient alors un frein : passer à SQLite (PDO, requêtes préparées) est
  recommandé.

Alternative la plus sûre pour un usage personnel : **ne pas exposer l'application publiquement** et y accéder par
VPN (Tailscale, WireGuard) ou par un proxy d'identité (Cloudflare Access, Authelia, oauth2-proxy). On obtient MFA et
protection contre la force brute sans écrire de code d'authentification ; il reste à corriger V3, V4 et V9 (V1 et V2 sont
corrigées).

### Étape 2 — Hébergement et serveur web

- VPS ou hébergement PHP avec PHP-FPM 8.5+, derrière **Caddy** (HTTPS Let's Encrypt automatique) ou Nginx + Certbot.
- Racine web = `front/dist` uniquement ; `/api/*` routé vers `api/index.php` ; `data/`, `api/src`, `api/tests`,
  `PROMPT.md`, `.git` **hors** de la racine web.
- HTTPS obligatoire, redirection HTTP → HTTPS, HSTS.
- Pare-feu : seuls 80/443 ouverts, SSH par clé uniquement (`PasswordAuthentication no`, `PermitRootLogin no`),
  `fail2ban`.
- PHP en production : `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `session.*` durci (voir étape 3),
  `open_basedir` limité au projet, `disable_functions` pour `exec`, `shell_exec`, `system`, `passthru`, `proc_open`,
  `popen`.

### Étape 3 — Authentification

- Mots de passe hachés avec `password_hash()` (Argon2id ou bcrypt) et vérifiés avec `password_verify()` ; longueur
  minimale 12, vérification contre les mots de passe compromis (API Have I Been Pwned, k-anonymity) recommandée.
- **Session côté serveur** (sessions PHP natives) plutôt que JWT : plus simple et révocable.
  - `session_regenerate_id(true)` à la connexion ;
  - cookie `Secure`, `HttpOnly`, `SameSite=Strict`, préfixe `__Host-` ;
  - `session.use_strict_mode=1`, `session.use_only_cookies=1` ;
  - expiration d'inactivité (ex. 30 min) et absolue (ex. 12 h) ; déconnexion qui détruit la session.
- **Double authentification (TOTP)** fortement recommandée pour des données bancaires (bibliothèque type
  `spomky-labs/otphp`, ou passkeys/WebAuthn).
- Limitation des tentatives de connexion (étape 6) et messages d'erreur identiques pour « compte inconnu » et
  « mot de passe faux ».
- Création de compte : désactivée (comptes créés en ligne de commande) si l'usage est personnel.

### Étape 4 — Autorisation et protection des routes

- Un contrôle unique dans `api/index.php`, **avant** `$router->dispatch()` : toute route sauf `POST /api/connexion`
  exige une session valide, sinon 401.
- Le front intercepte les 401 (dans `api()` de `front/src/api.ts`) et redirige vers une page de connexion.
- En multi-utilisateurs : le dossier de données est dérivé de l'utilisateur de la session, jamais d'un paramètre de
  requête.

### Étape 5 — Correctifs de l'application (V1 à V4, V9)

- ✅ `Content-Type` imposé + contrôle `Origin`/`Sec-Fetch-Site` (fait, V1) ; jeton CSRF à ajouter avec la session ;
- erreurs 500 génériques, `/api/sante` sans version ;
- en-têtes de sécurité (§1, V4) et script de thème déplacé dans un fichier ;
- `umask(0077)` ;
- tests automatisés pour chacun (requête sans session → 401, mauvais `Content-Type` → 415, mauvaise origine → 403).

### Étape 6 — Limitation de débit et journalisation

- Limitation par IP sur la connexion et sur l'API (serveur web) ; verrouillage temporaire après échecs répétés.
- Journal d'audit : connexions réussies/échouées, imports, suppressions de catégories et de règles (date, IP,
  utilisateur), sans jamais journaliser de mot de passe ni de données bancaires.
- Rotation des journaux (`logrotate`).

### Étape 7 — Données et sauvegardes

- Chiffrement du volume de données.
- Sauvegardes quotidiennes externes et chiffrées (`restic`/`borg` vers un stockage distinct), **testées en
  restauration**. Les sauvegardes locales de `data/sauvegardes/` ne protègent pas contre la perte du serveur.
- RGPD : si d'autres personnes utilisent l'application, leurs relevés bancaires sont des données personnelles
  (information, droit à l'effacement, hébergement dans l'UE de préférence).

### Étape 8 — Vérification avant ouverture

- Relancer `npm audit`, les tests PHP et JS.
- Scanner le site : Mozilla Observatory / securityheaders.com (en-têtes), SSL Labs (TLS, viser A+), OWASP ZAP en mode
  « baseline » (scan passif).
- Contrôler que `https://<domaine>/data/`, `/api/src/`, `/.git/`, `/PROMPT.md` renvoient 404.
- Utiliser la checklist **OWASP ASVS niveau 1** comme référence.

### Étape 9 — Maintien dans le temps

- Dependabot + CI (§2), mise à jour PHP à chaque version de correctif, revue mensuelle de `npm outdated`.
- Suivre la fin de vie : PHP 8.5 jusqu'à fin 2029, Node 24 jusqu'à avril 2028.

## 4. Plan d'action résumé

| Priorité | Action | Effort | État |
|---|---|---|---|
| Immédiat | Corriger V1 (CSRF) et V2 (Host), même en local | ~1 h | ✅ Fait le 11/11/2026 (`RequestGuard`, 7 tests) |
| Immédiat | Mettre à jour `vite`, `@vitejs/plugin-react`, `vitest` | 15 min | ⏳ Versions publiées il y a moins de 14 jours : via Dependabot vers le 25/11/2026 |
| Avant le 31/12/2026 | PHP 8.2 → 8.5 | 30 min + tests | ✅ Fait le 11/11/2026 (PHP 8.5.12) |
| Court terme | V3, V9 | 1 h | À faire |
| Court terme | Node 22.12 → 24.22.2 LTS | 15 min | ✅ Fait le 11/11/2026 |
| Avant mise en ligne | Étapes 1 à 8 (ou VPN / proxy d'identité + V3, V4, V9) | 2 à 5 jours selon le modèle choisi | À faire |
| Moyen terme | React 19, Recharts 3, TypeScript 7 | 1 à 2 jours | ✅ Fait le 11/11/2026 (graphiques vérifiés dans le navigateur) |
| Automatisation | Dependabot (délai de 14 jours) + CI GitHub Actions (tests, build, `npm audit`) | 1 h | ✅ Fait le 11/11/2026 ; premier passage de la CI à vérifier après le push |
