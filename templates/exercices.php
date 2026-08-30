<?php
declare(strict_types=1);
/** @var list<array> $exercices */
ob_start();
?>
<h1>Exercices</h1>
<p class="lead">
  N4 = juil. 2024 → juin 2025 (12 mois) ·
  <strong>N5 = juil. 2025 → déc. 2026 (18 mois)</strong> ·
  <strong>à partir du 1<sup>er</sup>&nbsp;janvier&nbsp;2027</strong> :
  tout repasse en <strong>année civile</strong> (N6 = 01/01/2027 → 31/12/2027, puis chaque année).
  L’exercice affiché partout est choisi automatiquement selon la date du jour.
</p>

<table class="data">
  <thead>
    <tr><th>Code</th><th>Libellé</th><th>Début</th><th>Fin</th><th class="num">Objectif CA HT</th><th>En cours</th></tr>
  </thead>
  <tbody>
  <?php if (!$exercices): ?>
    <tr><td colspan="6" class="muted">Aucun exercice. Lancez <code>php scripts/init_db.php</code>.</td></tr>
  <?php else: foreach ($exercices as $ex): ?>
    <tr>
      <td><?= e($ex['code']) ?></td>
      <td><?= e($ex['libelle']) ?></td>
      <td><?= date_fr($ex['date_debut']) ?></td>
      <td><?= date_fr($ex['date_fin']) ?></td>
      <td class="num"><?= isset($ex['objectif_ca_ht']) && $ex['objectif_ca_ht'] !== null ? euro((float) $ex['objectif_ca_ht']) : '—' ?></td>
      <td><?= (int) $ex['actif'] === 1 ? 'Oui' : '' ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
<?php
$content = ob_get_clean();
$title = 'Exercices';
$page = 'exercices';
require dirname(__DIR__) . '/templates/layout.php';
