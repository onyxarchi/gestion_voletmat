<?php
declare(strict_types=1);

/**
 * Initialise la base SQLite, les catégories TRI, les exercices N4/N5,
 * et le compte utilisatrice (mot de passe demandé en CLI).
 *
 * Usage : php scripts/init_db.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Voletmat\Database;

$root = dirname(__DIR__);
$schema = $root . '/sql/schema.sqlite.sql';
$path = (string) app_config('db.sqlite_path');

echo "Base SQLite : {$path}\n";
Database::migrateSqlite($schema);
$pdo = Database::pdo();

require_once $root . '/scripts/seed_reference_data.php';
seed_reference_data($pdo);
echo "Catégories TRI + exercices N4/N5 (seed).\n";

$nb = (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
if ($nb === 0) {
    echo "Création du compte utilisatrice.\n";
    $login = getenv('VOLETMAT_LOGIN') ?: 'sabine';
    $password = getenv('VOLETMAT_PASSWORD') ?: '';
    if (PHP_SAPI === 'cli' && $password === '') {
        echo "Identifiant [{$login}] : ";
        $line = trim((string) fgets(STDIN));
        if ($line !== '') {
            $login = $line;
        }
        echo 'Mot de passe : ';
        $password = trim((string) fgets(STDIN));
    }
    if ($password === '') {
        fwrite(STDERR, "Mot de passe vide. Définir VOLETMAT_PASSWORD ou saisir en CLI.\n");
        exit(1);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO utilisateurs (login, password_hash, nom) VALUES (?,?,?)')
        ->execute([$login, $hash, 'Sabine']);
    echo "Compte « {$login} » créé.\n";
} else {
    echo "Compte utilisatrice déjà présent.\n";
}

echo "OK. Lancez : php -S localhost:8088 -t public\n";
echo "Puis ouvrez http://localhost:8088/\n";
