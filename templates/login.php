<?php
declare(strict_types=1);
ob_start();
?>
<div class="form-box">
  <div class="login-hero">
    <img src="assets/img/logo-voletmat.png" alt="Vol&amp;Mat Architecture">
    <p class="app-label">Gestion</p>
  </div>
  <p class="lead" style="text-align:center">Accès réservé</p>
  <form method="post" action="<?= e(page_url('login')) ?>">
    <?= csrf_field() ?>
    <label for="login">Identifiant</label>
    <input type="text" id="login" name="login" required autocomplete="username">
    <label for="password">Mot de passe</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <div class="form-actions">
      <button class="btn" type="submit">Se connecter</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
$title = 'Connexion';
$page = 'login';
require dirname(__DIR__) . '/templates/layout.php';
