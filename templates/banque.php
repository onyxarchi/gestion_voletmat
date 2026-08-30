<?php
declare(strict_types=1);
/** @var list<array> $operations */
ob_start();
?>
<h1>Banque</h1>
<p class="lead">Opérations bancaires classées (feuille BANQUE). Import via le menu Import.</p>

<p><a class="btn secondary" href="<?= e(page_url('import')) ?>">Importer un relevé</a></p>

<?php if (!$operations): ?>
  <div class="empty">Aucune opération. Importez un relevé CIC (.xlsx) ou les données BANQUE du classeur.</div>
<?php else: ?>
<table class="data">
  <thead>
    <tr>
      <th>Date</th>
      <th>Valeur</th>
      <th>Libellé</th>
      <th class="num">Débit</th>
      <th class="num">Crédit</th>
      <th>TRI</th>
      <th>Mois</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($operations as $o): ?>
    <tr>
      <td><?= date_fr($o['date_operation']) ?></td>
      <td><?= date_fr($o['date_valeur'] ?? null) ?></td>
      <td><?= e($o['libelle']) ?></td>
      <td class="num"><?= $o['debit'] !== null ? euro((float) $o['debit']) : '' ?></td>
      <td class="num"><?= $o['credit'] !== null ? euro((float) $o['credit']) : '' ?></td>
      <td><?= e((string) ($o['categorie_code'] ?? '')) ?></td>
      <td class="muted"><?= e((string) ($o['annee_mois'] ?? '')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Banque';
$page = 'banque';
require dirname(__DIR__) . '/templates/layout.php';
