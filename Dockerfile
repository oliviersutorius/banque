# Image de Mes Comptes pour le poste local : construit le front puis le sert avec l'API via « php -S » (router.php).
# Lancement : docker compose up --build (voir compose.yaml et README, « Lancer avec Docker »).
# Pas pour la mise en ligne : en ligne, Caddy + PHP-FPM (deploy/).

# 1. Front : npm ci puis build (tsc + vite) ; seul front/dist est gardé
FROM node:24-alpine AS front
WORKDIR /front
COPY front/package.json front/package-lock.json ./
RUN npm ci
COPY front/ ./
RUN npm run build

# 2. Exécution : PHP CLI, avec mbstring, sodium et pdo_sqlite (présents dans l'image officielle)
FROM php:8.5-cli-alpine
RUN php -r 'foreach (["mbstring", "sodium", "pdo_sqlite"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Extension absente : $e\n"); exit(1); } }'
WORKDIR /app
COPY router.php ./
COPY api/ api/
COPY --from=front /front/dist front/dist/
# base/ et log/ sont montés depuis le poste (compose.yaml) ; créés ici pour une image lancée seule
RUN mkdir base log

# Écoute sur la boucle locale : avec le réseau de l'hôte (compose.yaml), le site n'est joignable que depuis le poste
# et l'API voit REMOTE_ADDR = 127.0.0.1 (« poste local », voir api/src/AuthConfig.php)
CMD ["php", "-S", "127.0.0.1:8000", "router.php"]
