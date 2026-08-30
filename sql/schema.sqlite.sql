-- Schéma SQLite — Vol&Mat Gestion
-- Indépendant d’ONYX. Montants stockés tels quels (jamais inventés).

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS utilisateurs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    login TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    nom TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS exercices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    libelle TEXT NOT NULL,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    actif INTEGER NOT NULL DEFAULT 0,
    objectif_ca_ht REAL,
    marge_pct REAL NOT NULL DEFAULT 0,
    previ_mois INTEGER NOT NULL DEFAULT 12,
    solde_ouverture REAL,
    solde_ouverture_date TEXT,
    CHECK (date_debut <= date_fin)
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    libelle TEXT NOT NULL,
    ordre INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS affaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id),
    reference TEXT,
    client TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL DEFAULT 'autre',
    montant_contrat_ht REAL,
    encaisse_n1 REAL NOT NULL DEFAULT 0,
    fini INTEGER NOT NULL DEFAULT 0,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS echeances_facturation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    affaire_id INTEGER NOT NULL REFERENCES affaires(id) ON DELETE CASCADE,
    annee_mois TEXT NOT NULL,
    montant_ht REAL NOT NULL DEFAULT 0,
    statut TEXT NOT NULL DEFAULT 'a_facturer',
    couleur TEXT,
    ecart_ok INTEGER NOT NULL DEFAULT 0,
    UNIQUE (affaire_id, annee_mois)
);

CREATE TABLE IF NOT EXISTS factures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id),
    numero TEXT NOT NULL,
    date_facture TEXT NOT NULL,
    client TEXT NOT NULL DEFAULT '',
    ht REAL NOT NULL DEFAULT 0,
    taux_tva REAL NOT NULL DEFAULT 0.20,
    tva REAL NOT NULL DEFAULT 0,
    ttc REAL NOT NULL DEFAULT 0,
    canal TEXT,
    affaire_id INTEGER REFERENCES affaires(id),
    notes TEXT,
    statut_paiement TEXT,
    est_mar INTEGER,
    UNIQUE (exercice_id, numero)
);

CREATE TABLE IF NOT EXISTS operations_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exercice_id INTEGER REFERENCES exercices(id),
    date_operation TEXT NOT NULL,
    date_valeur TEXT,
    libelle TEXT NOT NULL,
    debit REAL,
    credit REAL,
    categorie_code TEXT REFERENCES categories(code),
    annee_mois TEXT,
    source TEXT NOT NULL DEFAULT 'manuel',
    import_id INTEGER,
    empreinte TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (empreinte)
);

CREATE TABLE IF NOT EXISTS imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fichier_nom TEXT NOT NULL,
    format TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'brouillon',
    lignes_lues INTEGER NOT NULL DEFAULT 0,
    lignes_acceptees INTEGER NOT NULL DEFAULT 0,
    lignes_rejetees INTEGER NOT NULL DEFAULT 0,
    lignes_doublons INTEGER NOT NULL DEFAULT 0,
    solde_initial REAL,
    solde_final REAL,
    ecart_solde REAL,
    controle_json TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    valide_at TEXT
);

CREATE TABLE IF NOT EXISTS imports_lignes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_id INTEGER NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    ligne_no INTEGER NOT NULL,
    date_operation TEXT,
    date_valeur TEXT,
    libelle TEXT,
    debit REAL,
    credit REAL,
    statut TEXT NOT NULL DEFAULT 'ok',
    motif TEXT,
    empreinte TEXT,
    raw_json TEXT
);

CREATE TABLE IF NOT EXISTS rapprochements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation_id INTEGER NOT NULL REFERENCES operations_bancaires(id) ON DELETE CASCADE,
    facture_id INTEGER NOT NULL REFERENCES factures(id) ON DELETE CASCADE,
    montant REAL NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (operation_id, facture_id)
);

CREATE TABLE IF NOT EXISTS budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id),
    categorie_code TEXT NOT NULL REFERENCES categories(code),
    montant_ht REAL NOT NULL DEFAULT 0,
    montant_tva REAL NOT NULL DEFAULT 0,
    montant_ttc REAL NOT NULL DEFAULT 0,
    UNIQUE (exercice_id, categorie_code)
);

CREATE TABLE IF NOT EXISTS ca_historique (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    annee INTEGER NOT NULL UNIQUE,
    ca_ht REAL,
    source TEXT NOT NULL DEFAULT 'saisi'
);

CREATE INDEX IF NOT EXISTS idx_factures_exercice ON factures(exercice_id);
CREATE INDEX IF NOT EXISTS idx_factures_date ON factures(date_facture);
CREATE INDEX IF NOT EXISTS idx_ops_date ON operations_bancaires(date_operation);
CREATE INDEX IF NOT EXISTS idx_ops_mois ON operations_bancaires(annee_mois);
CREATE INDEX IF NOT EXISTS idx_ops_cat ON operations_bancaires(categorie_code);
CREATE INDEX IF NOT EXISTS idx_echeances_mois ON echeances_facturation(annee_mois);
