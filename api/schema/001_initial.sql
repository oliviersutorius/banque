-- Schéma initial de Mes Comptes (SQLite 3.37+, tables STRICT).
-- Appliqué par Database::migrate ; la version du schéma est gardée dans PRAGMA user_version.

-- Catégories sur deux niveaux. Une dépense est classée dans une sous-catégorie (parent_id renseigné) ;
-- un revenu dans une catégorie de premier niveau (les revenus n'ont pas de sous-catégorie).
CREATE TABLE categories (
    id        INTEGER PRIMARY KEY,
    sens      TEXT    NOT NULL CHECK (sens IN ('dépense', 'revenu')),
    parent_id INTEGER REFERENCES categories (id) ON DELETE CASCADE,
    nom       TEXT    NOT NULL CHECK (length(trim(nom)) > 0),
    icone     TEXT,
    -- Ordre d'affichage parmi les catégories de même parent ; donne la couleur dans les graphiques
    position  INTEGER NOT NULL,
    -- « À catégoriser » et « Non classé » : ni renommées ni supprimées
    protegee  INTEGER NOT NULL DEFAULT 0 CHECK (protegee IN (0, 1))
) STRICT;

CREATE UNIQUE INDEX categories_nom_niveau1 ON categories (sens, nom) WHERE parent_id IS NULL;
CREATE UNIQUE INDEX categories_nom_niveau2 ON categories (parent_id, nom) WHERE parent_id IS NOT NULL;
CREATE INDEX categories_parent_position ON categories (parent_id, position);

-- Une sous-catégorie : même sens que son parent, parent de premier niveau, uniquement pour les dépenses
CREATE TRIGGER categories_parent_valide_insert BEFORE INSERT ON categories
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'sous-catégorie invalide')
    WHERE NEW.sens <> 'dépense'
       OR NOT EXISTS (SELECT 1 FROM categories p WHERE p.id = NEW.parent_id AND p.sens = NEW.sens AND p.parent_id IS NULL);
END;

CREATE TRIGGER categories_parent_valide_update BEFORE UPDATE OF parent_id, sens ON categories
BEGIN
    SELECT RAISE(ABORT, 'modification de la hiérarchie interdite');
END;

-- Opérations bancaires. L'id croissant reproduit l'ordre d'arrivée : ORDER BY date, id.
CREATE TABLE operations (
    id               INTEGER PRIMARY KEY,
    date             TEXT    NOT NULL CHECK (date GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9]'),
    mois             TEXT    NOT NULL GENERATED ALWAYS AS (substr(date, 1, 4) || substr(date, 6, 2)) STORED,
    libelle          TEXT    NOT NULL CHECK (length(trim(libelle)) > 0),
    sens             TEXT    NOT NULL CHECK (sens IN ('dépense', 'revenu')),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes >= 0),
    categorie_id     INTEGER NOT NULL REFERENCES categories (id) ON DELETE RESTRICT
) STRICT;

CREATE INDEX operations_mois_date ON operations (mois, date, id);
CREATE INDEX operations_categorie ON operations (categorie_id);

-- Règles de catégorisation. L'id croissant départage deux règles de même priorité (ancien ordre du fichier).
CREATE TABLE regles (
    id             INTEGER PRIMARY KEY,
    motif          TEXT    NOT NULL CHECK (length(trim(motif)) > 0),
    sens           TEXT    NOT NULL CHECK (sens IN ('dépense', 'revenu')),
    categorie_id   INTEGER NOT NULL REFERENCES categories (id) ON DELETE RESTRICT,
    origine        TEXT    NOT NULL CHECK (origine IN ('initiale', 'apprise')),
    type_operation TEXT,
    a_confirmer    INTEGER NOT NULL DEFAULT 0 CHECK (a_confirmer IN (0, 1))
) STRICT;

CREATE INDEX regles_categorie ON regles (categorie_id);

-- Autres mots requis par une règle (condition ET), dans l'ordre de saisie
CREATE TABLE regle_mots (
    regle_id INTEGER NOT NULL REFERENCES regles (id) ON DELETE CASCADE,
    position INTEGER NOT NULL,
    mot      TEXT    NOT NULL CHECK (length(trim(mot)) > 0),
    PRIMARY KEY (regle_id, position)
) STRICT, WITHOUT ROWID;

-- La catégorie d'une opération ou d'une règle est une « feuille » du même sens :
-- sous-catégorie pour une dépense, catégorie de premier niveau pour un revenu.
CREATE TRIGGER operations_categorie_valide_insert BEFORE INSERT ON operations
BEGIN
    SELECT RAISE(ABORT, 'catégorie incompatible avec le sens de l''opération')
    WHERE NOT EXISTS (
        SELECT 1 FROM categories c
        WHERE c.id = NEW.categorie_id AND c.sens = NEW.sens
          AND ((NEW.sens = 'dépense' AND c.parent_id IS NOT NULL) OR (NEW.sens = 'revenu' AND c.parent_id IS NULL))
    );
END;

CREATE TRIGGER operations_categorie_valide_update BEFORE UPDATE OF categorie_id, sens ON operations
BEGIN
    SELECT RAISE(ABORT, 'catégorie incompatible avec le sens de l''opération')
    WHERE NOT EXISTS (
        SELECT 1 FROM categories c
        WHERE c.id = NEW.categorie_id AND c.sens = NEW.sens
          AND ((NEW.sens = 'dépense' AND c.parent_id IS NOT NULL) OR (NEW.sens = 'revenu' AND c.parent_id IS NULL))
    );
END;

CREATE TRIGGER regles_categorie_valide_insert BEFORE INSERT ON regles
BEGIN
    SELECT RAISE(ABORT, 'catégorie incompatible avec le sens de la règle')
    WHERE NOT EXISTS (
        SELECT 1 FROM categories c
        WHERE c.id = NEW.categorie_id AND c.sens = NEW.sens
          AND ((NEW.sens = 'dépense' AND c.parent_id IS NOT NULL) OR (NEW.sens = 'revenu' AND c.parent_id IS NULL))
    );
END;

CREATE TRIGGER regles_categorie_valide_update BEFORE UPDATE OF categorie_id, sens ON regles
BEGIN
    SELECT RAISE(ABORT, 'catégorie incompatible avec le sens de la règle')
    WHERE NOT EXISTS (
        SELECT 1 FROM categories c
        WHERE c.id = NEW.categorie_id AND c.sens = NEW.sens
          AND ((NEW.sens = 'dépense' AND c.parent_id IS NOT NULL) OR (NEW.sens = 'revenu' AND c.parent_id IS NULL))
    );
END;

-- Réglages de l'application (charte graphique…)
CREATE TABLE parametres (
    cle    TEXT PRIMARY KEY,
    valeur TEXT NOT NULL
) STRICT, WITHOUT ROWID;

-- Le compte unique
CREATE TABLE compte (
    id               INTEGER PRIMARY KEY CHECK (id = 1),
    identifiant      TEXT    NOT NULL CHECK (length(trim(identifiant)) > 0),
    mot_de_passe     TEXT    NOT NULL,           -- empreinte Argon2id
    totp             TEXT,                       -- secret chiffré (SecretBox), NULL = sans double authentification
    dernier_pas_totp INTEGER NOT NULL DEFAULT 0
) STRICT;

-- Empreintes SHA-256 des codes de secours restants (un code utilisé est supprimé)
CREATE TABLE codes_secours (
    id        INTEGER PRIMARY KEY,
    compte_id INTEGER NOT NULL REFERENCES compte (id) ON DELETE CASCADE,
    empreinte TEXT    NOT NULL UNIQUE
) STRICT;

-- Échecs de connexion par IP (« ip:<adresse> ») et pour le compte (« compte »)
CREATE TABLE tentatives (
    cle     TEXT    PRIMARY KEY,
    echecs  INTEGER NOT NULL,
    dernier INTEGER NOT NULL,
    jusqu_a INTEGER
) STRICT, WITHOUT ROWID;

-- Sessions PHP (DatabaseSessionHandler)
CREATE TABLE sessions (
    id        TEXT    PRIMARY KEY,
    donnees   TEXT    NOT NULL,
    expire_le INTEGER NOT NULL
) STRICT, WITHOUT ROWID;

CREATE INDEX sessions_expiration ON sessions (expire_le);
