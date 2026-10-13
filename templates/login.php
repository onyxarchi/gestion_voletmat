<?php
declare(strict_types=1);
ob_start();
$openReset = !empty($_GET['reset']);
?>
<div class="form-box">
  <div class="login-hero">
    <img src="assets/img/logo-voletmat.png" alt="Vol&amp;Mat Architecture">
    <p class="app-label">Gestion</p>
  </div>
  <p class="lead" style="text-align:center">Accès réservé</p>
  <form method="post" action="<?= e(page_url('login')) ?>" id="login-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="login">
    <label for="login">Identifiant</label>
    <input type="text" id="login" name="login" value="sabine" required autocomplete="username">
    <label for="password">Mot de passe</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <div class="form-actions login-actions">
      <button class="btn" type="submit">Se connecter</button>
      <button class="btn secondary" type="button" id="btn-mdp-oublie" aria-haspopup="dialog" aria-controls="mdp-oublie-dialog">
        Mot de passe oublié
      </button>
    </div>
  </form>
</div>

<dialog class="login-dialog" id="mdp-oublie-dialog"<?= $openReset ? ' open' : '' ?>>
  <form method="post" action="<?= e(page_url('login')) ?>" class="login-dialog-inner">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="nouveau_mdp">
    <h2>Nouveau mot de passe</h2>
    <p class="lead">Choisissez un mot de passe (min. 6 caractères), validez, puis retapez-le dans la fenêtre de connexion.</p>
    <label for="reset-login">Identifiant</label>
    <input type="text" id="reset-login" name="login" value="sabine" required autocomplete="username">
    <label for="reset-password">Nouveau mot de passe</label>
    <input type="password" id="reset-password" name="password" required minlength="6" autocomplete="new-password">
    <label for="reset-password-confirm">Confirmer</label>
    <input type="password" id="reset-password-confirm" name="password_confirm" required minlength="6" autocomplete="new-password">
    <div class="form-actions">
      <button class="btn" type="submit">Valider</button>
      <button class="btn secondary" type="button" id="btn-mdp-annuler" value="cancel">Annuler</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var dlg = document.getElementById('mdp-oublie-dialog');
  var openBtn = document.getElementById('btn-mdp-oublie');
  var cancelBtn = document.getElementById('btn-mdp-annuler');
  if (!dlg || !openBtn) return;
  openBtn.addEventListener('click', function () {
    var mainLogin = document.getElementById('login');
    var resetLogin = document.getElementById('reset-login');
    if (mainLogin && resetLogin && mainLogin.value) resetLogin.value = mainLogin.value;
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', '');
    var pw = document.getElementById('reset-password');
    if (pw) pw.focus();
  });
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      if (typeof dlg.close === 'function') dlg.close();
      else dlg.removeAttribute('open');
    });
  }
  dlg.addEventListener('click', function (ev) {
    if (ev.target === dlg) {
      if (typeof dlg.close === 'function') dlg.close();
      else dlg.removeAttribute('open');
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
$title = 'Connexion';
$page = 'login';
require dirname(__DIR__) . '/templates/layout.php';
