<?php
declare(strict_types=1);

/**
 * Installation initiale via le navigateur (NAS Web Station).
 * Accessible uniquement tant qu’aucun utilisateur n’existe, ou avec ?force=1
 * après suppression manuelle de storage/data/*.sqlite (attention).
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Voletmat\Database;

header('Content-Type: text/html; charset=utf-8');

$pdo = null;
$error = null;
$done = false;
$info = [];

try {
    $dbPath = (string) app_config('db.sqlite_path');
    $info['db_path'] = $dbPath;
    $info['writable_dir'] = is_writable(dirname($dbPath)) || (!file_exists(dirname($dbPath)) && is_writable(dirname(dirname($dbPath))));

    Database::migrateSqlite(dirname(__DIR__) . '/sql/schema.sqlite.sql');
    $pdo = Database::pdo();
    $nbUsers = (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    $info['users'] = $nbUsers;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null && $pdo) {
    $nbUsers = (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    if ($nbUsers > 0 && empty($_POST['reset_ok'])) {
        $error = 'Un compte existe déjà. Connexion via index.php — setup désactivé.';
    } else {
        $login = trim((string) ($_POST['login'] ?? 'sabine'));
        $password = (string) ($_POST['password'] ?? '');
        $nom = trim((string) ($_POST['nom'] ?? 'Sabine'));
        if ($login === '' || strlen($password) < 6) {
            $error = 'Identifiant requis et mot de passe d’au moins 6 caractères.';
        } else {
            // Catégories + exercices (même jeu que init_db.php)
            require dirname(__DIR__) . '/scripts/seed_reference_data.php';
            seed_reference_data($pdo);

            if ($nbUsers > 0) {
                $pdo->prepare('UPDATE utilisateurs SET password_hash=?, nom=? WHERE login=?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $nom, $login]);
            } else {
                $pdo->prepare('INSERT INTO utilisateurs (login, password_hash, nom) VALUES (?,?,?)')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $nom]);
            }
            $done = true;
        }
    }
}

$nbUsers = 0;
if ($pdo && $error === null) {
    $nbUsers = (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installation — Vol&Mat Gestion</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<main class="wrap">
  <div class="form-box" style="max-width:480px">
    <h1>Installation NAS</h1>
    <p class="lead">Première configuration de Vol&amp;Mat Gestion sur le Synology.</p>

    <?php if ($error): ?>
      <div class="flash erreur"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="flash ok">Compte créé. Vous pouvez vous connecter.</div>
      <p><a class="btn" href="index.php?page=login">Aller à la connexion</a></p>
      <p class="lead">Si les factures sont vides, importez les classeurs depuis un Mac (voir README) ou déposez une base SQLite déjà remplie dans <code>storage/data/</code>.</p>
    <?php elseif ($nbUsers > 0): ?>
      <div class="flash info">Installation déjà faite (<?= (int) $nbUsers ?> compte).</div>
      <p><a class="btn" href="index.php?page=login">Connexion</a></p>
      <p class="lead">Base : <code><?= e((string) ($info['db_path'] ?? '')) ?></code></p>
    <?php else: ?>
      <form method="post">
        <label for="nom">Nom</label>
        <input type="text" id="nom" name="nom" value="Sabine">
        <label for="login">Identifiant</label>
        <input type="text" id="login" name="login" value="sabine" required>
        <label for="password">Mot de passe (min. 6)</label>
        <input type="password" id="password" name="password" required minlength="6">
        <div class="form-actions">
          <button class="btn" type="submit">Créer le compte</button>
        </div>
      </form>
      <p class="lead" style="margin-top:1rem;font-size:0.85rem">
        Chemin base : <code><?= e((string) ($info['db_path'] ?? '')) ?></code><br>
        Dossier writable : <?= !empty($info['writable_dir']) ? 'oui' : 'non — vérifier les droits sur storage/' ?>
      </p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
