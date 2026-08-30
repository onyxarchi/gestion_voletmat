<?php
declare(strict_types=1);
/** @var array|null $exercice */
/** @var array $analytique */
ob_start();

$mois = $analytique['mois'] ?? [];
$lignes = $analytique['lignes'] ?? [];
$totauxMois = $analytique['totaux_mois'] ?? [];
$totalGeneral = (float) ($analytique['total_general'] ?? 0);
?>
<h1>Compta analytique</h1>
<?php if ($exercice): ?>
  <p class="lead">
    Sommes par code TRI et par mois (banque) —
    <strong><?= e($exercice['libelle']) ?></strong>.
  </p>
<?php else: ?>
  <p class="lead">Aucun exercice pour la date du jour.</p>
<?php endif; ?>

<?php if (!$exercice): ?>
  <div class="empty">Impossible d’afficher l’analytique sans exercice.</div>
<?php elseif (!$lignes): ?>
  <div class="empty">Aucune opération bancaire classée pour cet exercice.</div>
<?php else: ?>
<div class="cards cards-compact">
  <div class="card">
    <div class="label">Codes TRI</div>
    <div class="value"><?= count($lignes) ?></div>
  </div>
  <div class="card">
    <div class="label">Mois</div>
    <div class="value"><?= count($mois) ?></div>
  </div>
  <div class="card">
    <div class="label">Total</div>
    <div class="value"><?= euro($totalGeneral) ?></div>
  </div>
</div>

<div class="planning-scroll analytique-scroll">
  <table class="data analytique-grid">
    <thead>
      <tr>
        <th class="sticky-col col-tri">TRI</th>
        <th class="sticky-col col-lib">Libellé</th>
        <?php foreach ($mois as $m): ?>
          <th class="num"><?= e($m['label']) ?></th>
        <?php endforeach; ?>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lignes as $l):
        $fam = preg_replace('/[^a-z_]/', '', (string) ($l['famille'] ?? 'neutre')) ?: 'neutre';
    ?>
      <tr class="prev-row prev-<?= e($fam) ?>">
        <td class="sticky-col col-tri"><strong><?= e($l['code']) ?></strong></td>
        <td class="sticky-col col-lib"><?= e($l['libelle']) ?></td>
        <?php foreach ($mois as $m):
            $v = (float) ($l['mois'][$m['key']] ?? 0);
        ?>
          <td class="num"><?= abs($v) < 0.005 ? '' : euro($v) ?></td>
        <?php endforeach; ?>
        <td class="num"><strong><?= euro((float) $l['total']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="sticky-col col-tri" colspan="2"><strong>Totaux</strong></td>
        <?php foreach ($mois as $m):
            $t = (float) ($totauxMois[$m['key']] ?? 0);
        ?>
          <td class="num"><?= abs($t) < 0.005 ? '' : euro($t) ?></td>
        <?php endforeach; ?>
        <td class="num"><strong><?= euro($totalGeneral) ?></strong></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Compta analytique';
$page = 'analytique';
require dirname(__DIR__) . '/templates/layout.php';
