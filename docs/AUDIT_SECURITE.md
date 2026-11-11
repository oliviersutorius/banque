# Audit de sécurité — Mes Comptes

Date : 11 novembre 2026 — périmètre : `api/`, `router.php`, `front/`, environnement local (PHP 8.2.35, Node 22.12.0,
npm 10.9.0).

## Synthèse

| Contexte | Verdict |
|---|---|
| Usage actuel (local, `php -S localhost`) | **Correct**, avec deux failles exploitables depuis le navigateur (CSRF, DNS rebinding) à corriger |
| Mise en ligne avec authentification | **Pas prêt** : l'architecture actuelle n'a ni identité, ni session, ni cloisonnement des données, ni durcissement HTTP |

Points forts constatés : typage strict partout, validation systématique des entrées (mois `YYYYMM`, dates, montants,
sens, catégories, tailles), aucun chemin de fichier construit à partir d'une saisie libre, motifs de règles échappés
avec `preg_quote` (pas de ReDoS), écritures atomiques avec verrou et sauvegardes, aucune `eval`/`exec`/
`unserialize`, aucun `dangerouslySetInnerHTML` côté React, `npm audit` : **0 vulnérabilité**, dossier `data/` ignoré
par git.

## 1. Vulnérabilités

Gravité évaluée pour l'usage **actuel** ; la colonne « En ligne » indique la gravité une fois exposé.

| # | Constat | Actuel | En ligne |
|---|---|---|---|
| V1 | CSRF sur les routes `POST` | Moyenne | Critique |
| V2 | DNS rebinding : aucun contrôle de l'en-tête `Host` | Moyenne | — (remplacé par V5) |
| V3 | Messages d'exception internes renvoyés au client (500) | Faible | Moyenne |
| V4 | Aucun en-tête de sécurité HTTP | Faible | Élevée |
| V5 | Aucune authentification ni autorisation | Nulle (local) | **Critique** |
| V6 | Serveur intégré `php -S` | Nulle | **Critique** |
| V7 | Données bancaires stockées en clair | Faible | Élevée |
| V8 | Aucune limitation de débit | Nulle | Moyenne |
| V9 | Permissions des fichiers de données en 0664 | Faible | Moyenne |

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

### V2 — DNS rebinding

Le serveur répond quel que soit l'en-tête `Host`. Une page dont le domaine est réaffecté vers `127.0.0.1` peut lire
toute l'API (`/api/annee/2025`, `/api/mois`…) en « même origine ».

**Correctif** : dans `router.php`, refuser (421/403) toute requête dont `Host` n'est pas `localhost:8000`,
`127.0.0.1:8000` ou `[::1]:8000`. En ligne : liste blanche du nom de domaine dans le serveur web.

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
| PHP | 8.2.35 | Correctifs de **sécurité uniquement jusqu'au 31/12/2026** — fin de vie dans 7 semaines | **Urgent** : passer en 8.4 (support sécurité jusqu'à fin 2028) ou 8.5 |
| Node.js | 22.12.0 | 22 en maintenance LTS jusqu'à avril 2027 ; 22.12 est ancien dans la branche | Mettre à jour vers la dernière 22.x tout de suite, puis Node 24 LTS |
| npm | 10.9.0 | Fourni avec Node 22 | Suivra Node |

Node ne sert qu'à **construire** le front (il ne tourne pas en production), son exposition est donc limitée ; PHP, lui,
est exposé directement.

### Dépendances du front (`npm outdated`)

`npm audit` : 0 vulnérabilité connue.

| Paquet | Actuelle | Dernière | Type | Recommandation |
|---|---|---|---|---|
| vite | 8.3.5 | 8.3.8 | correctif | **À faire** (Vite a régulièrement des correctifs de sécurité du serveur de dev) |
| @vitejs/plugin-react | 6.1.2 | 6.3.0 | mineure | À faire |
| vitest | 5.0.5 | 5.1.0 | mineure | À faire |
| react, react-dom | 18.3.1 | 19.3.1 | **majeure** | Recommandé avant la mise en ligne : React 18 ne reçoit plus d'évolutions. Migration simple ici (pas de `forwardRef`/`defaultProps` hérités à vérifier) |
| @types/react, @types/react-dom | 18.x | 19.3.0 | majeure | Avec React 19 |
| recharts | 2.15.0 | 3.10.1 | **majeure** | La 2.x n'évolue plus ; migration à tester (API des tooltips et des légendes modifiée) |
| typescript | 5.6.3 | 7.0.2 | **majeure** | Outil de build uniquement, sans impact sécurité ; passer au moins à la dernière 5.x, TS 7 (compilateur natif) dans un second temps |
| react-router-dom | 7.18.5 | à jour | — | — |

Ordre conseillé : correctifs et mineures (`vite`, `@vitejs/plugin-react`, `vitest`) → React 19 + types → Recharts 3
→ TypeScript. Garder la règle actuelle : versions exactes, publiées depuis au moins deux semaines, `npm audit` après
chaque passage, tests (`npm test`, `php api/tests/run.php`) et vérification manuelle des graphiques.

### Automatisation

- Activer **Dependabot** (ou Renovate) sur le dépôt GitHub pour npm, avec un délai de 14 jours (`cooldown`).
- Ajouter `npm audit --audit-level=high` et les tests PHP/JS dans une CI (GitHub Actions).
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
protection contre la force brute sans écrire de code d'authentification ; il reste à corriger V1–V4 et V9.

### Étape 2 — Hébergement et serveur web

- VPS ou hébergement PHP avec PHP-FPM 8.4+, derrière **Caddy** (HTTPS Let's Encrypt automatique) ou Nginx + Certbot.
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

- `Content-Type` imposé + contrôle `Origin`/`Sec-Fetch-Site` + jeton CSRF ;
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
- Suivre la fin de vie : PHP 8.4 jusqu'à fin 2028, Node 24 jusqu'à avril 2028.

## 4. Plan d'action résumé

| Priorité | Action | Effort |
|---|---|---|
| Immédiat | Corriger V1 (CSRF) et V2 (Host), même en local | ~1 h |
| Immédiat | Mettre à jour `vite`, `@vitejs/plugin-react`, `vitest` | 15 min |
| Avant le 31/12/2026 | PHP 8.2 → 8.4 | 30 min + tests |
| Court terme | V3, V9, mise à jour Node 22.x | 1 h |
| Avant mise en ligne | Étapes 1 à 8 (ou VPN / proxy d'identité + V1–V4, V9) | 2 à 5 jours selon le modèle choisi |
| Moyen terme | React 19, Recharts 3, TypeScript | 1 à 2 jours |
