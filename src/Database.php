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
