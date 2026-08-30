<?php
declare(strict_types=1);

namespace Voletmat;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = app_config('db');
        $driver = $cfg['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $path = $cfg['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Impossible de créer le dossier SQLite : ' . $dir);
            }
            $dsn = 'sqlite:' . $path;
            self::$pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::ensureSqliteColumns(self::$pdo);
            return self::$pdo;
        }

        if ($driver === 'mysql') {
            $m = $cfg['mysql'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $m['host'],
                (int) $m['port'],
                $m['dbname'],
                $m['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $m['user'], $m['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return self::$pdo;
        }

        throw new RuntimeException('Driver de base inconnu : ' . $driver);
    }

    /** Migrations légères pour bases déjà créées. */
    private static function ensureSqliteColumns(PDO $pdo): void
    {
        $cols = $pdo->query('PRAGMA table_info(affaires)')->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('fini', $names, true)) {
            $pdo->exec('ALTER TABLE affaires ADD COLUMN fini INTEGER NOT NULL DEFAULT 0');
        }
        self::dropAffairesReferenceUnique($pdo);

        $exCols = array_column($pdo->query('PRAGMA table_info(exercices)')->fetchAll(), 'name');
        if (!in_array('objectif_ca_ht', $exCols, true)) {
            $pdo->exec('ALTER TABLE exercices ADD COLUMN objectif_ca_ht REAL');
        }
        if (!in_array('marge_pct', $exCols, true)) {
            // Marge prévisionnelle en % (ex. 5 = 5 %) — Excel : OBJECTIF = total × (1 + marge)
            $pdo->exec('ALTER TABLE exercices ADD COLUMN marge_pct REAL NOT NULL DEFAULT 0');
        }
        if (!in_array('previ_mois', $exCols, true)) {
            // Base mensuelle des budgets récurrents (12 à l’import Excel ; 18 après transposition N5)
            $pdo->exec('ALTER TABLE exercices ADD COLUMN previ_mois INTEGER NOT NULL DEFAULT 12');
        }
        // Objectifs CA HT issus du Prévisionnel Excel (feuille OBJECTIF CA HT)
        $pdo->exec("UPDATE exercices SET objectif_ca_ht = 91760 WHERE code = 'N4' AND objectif_ca_ht IS NULL");
        $pdo->exec("UPDATE exercices SET objectif_ca_ht = 109561.65 WHERE code = 'N5' AND objectif_ca_ht IS NULL");

        $echCols = array_column($pdo->query('PRAGMA table_info(echeances_facturation)')->fetchAll(), 'name');
        if (!in_array('ecart_ok', $echCols, true)) {
            $pdo->exec('ALTER TABLE echeances_facturation ADD COLUMN ecart_ok INTEGER NOT NULL DEFAULT 0');
        }

        $facCols = array_column($pdo->query('PRAGMA table_info(factures)')->fetchAll(), 'name');
        if (!in_array('statut_paiement', $facCols, true)) {
            // NULL = auto (lié au planning) · paye | facture | litige = forcé à la main
            $pdo->exec('ALTER TABLE factures ADD COLUMN statut_paiement TEXT');
        }
        if (!in_array('est_mar', $facCols, true)) {
            // NULL = auto · 0/1 = forcé à la main
            $pdo->exec('ALTER TABLE factures ADD COLUMN est_mar INTEGER');
        }

        $impCols = array_column($pdo->query('PRAGMA table_info(imports)')->fetchAll(), 'name');
        if (!in_array('solde_initial', $impCols, true)) {
            $pdo->exec('ALTER TABLE imports ADD COLUMN solde_initial REAL');
        }
        if (!in_array('solde_final', $impCols, true)) {
            $pdo->exec('ALTER TABLE imports ADD COLUMN solde_final REAL');
        }
        if (!in_array('ecart_solde', $impCols, true)) {
            $pdo->exec('ALTER TABLE imports ADD COLUMN ecart_solde REAL');
        }
        if (!in_array('controle_json', $impCols, true)) {
            $pdo->exec('ALTER TABLE imports ADD COLUMN controle_json TEXT');
        }
    }

    /**
     * STRIPE / Divers (et doublons) peuvent partager la même référence :
     * on retire UNIQUE(exercice_id, reference) des bases déjà créées.
     */
    private static function dropAffairesReferenceUnique(PDO $pdo): void
    {
        $row = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'affaires'"
        )->fetch();
        $sql = (string) ($row['sql'] ?? '');
        if ($sql === '' || !preg_match('/UNIQUE\s*\(\s*exercice_id\s*,\s*reference\s*\)/i', $sql)) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE affaires__new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    exercice_id INTEGER NOT NULL REFERENCES exercices(id),
                    reference TEXT,
                    client TEXT NOT NULL DEFAULT \'\',
                    type TEXT NOT NULL DEFAULT \'autre\',
                    montant_contrat_ht REAL,
                    encaisse_n1 REAL NOT NULL DEFAULT 0,
                    fini INTEGER NOT NULL DEFAULT 0,
                    notes TEXT
                )'
            );
            $pdo->exec(
                'INSERT INTO affaires__new
                 (id, exercice_id, reference, client, type, montant_contrat_ht, encaisse_n1, fini, notes)
                 SELECT id, exercice_id, reference, client, type, montant_contrat_ht, encaisse_n1,
                        COALESCE(fini, 0), notes
                 FROM affaires'
            );
            $pdo->exec('DROP TABLE affaires');
            $pdo->exec('ALTER TABLE affaires__new RENAME TO affaires');
            // Nettoyer les suffixes « — Client » ajoutés pour forcer l’unicité
            $pdo->exec(
                "UPDATE affaires SET reference = 'STRIPE'
                 WHERE upper(reference) LIKE 'STRIPE — %'
                    OR upper(reference) LIKE 'STRIPE - %'"
            );
            $pdo->exec(
                "UPDATE affaires SET reference = 'Divers'
                 WHERE upper(reference) LIKE 'DIVERS — %'
                    OR upper(reference) LIKE 'DIVERS - %'"
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    public static function migrateSqlite(string $schemaFile): void
    {
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException('Schéma introuvable : ' . $schemaFile);
        }
        self::pdo()->exec($sql);
        self::ensureSqliteColumns(self::pdo());
    }
}
