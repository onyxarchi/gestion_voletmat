<?php
declare(strict_types=1);
/** @var array|null $exercice */
/** @var array $analytique */
ob_start();

$mois = $analytique['mois'] ?? [];
$lignes = $analytique['lignes'] ?? [];
$totauxMois = $analytique['totaux_mois'] ?? [];
$banqueMois = $analytique['banque_mois'] ?? [];
?>
<h1>Compta analytique</h1>
<?php if (!$exercice): ?>
  <p class="lead">Aucun exercice pour la date du jour.</p>
  <div class="empty">Impossible d’afficher l’analytique sans exercice.</div>
<?php elseif (!$lignes): ?>
  <div class="empty">Aucune opération bancaire classée pour cet exercice.</div>
<?php else: ?>

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
        $anomMois = array_fill_keys($l['anomalies_mois'] ?? [], true);
        $rowBug = $anomMois !== [];
    ?>
      <tr class="prev-row prev-<?= e($fam) ?><?= $rowBug ? ' analytique-row-bug' : '' ?>">
        <td class="sticky-col col-tri"><strong><?= e($l['code']) ?></strong></td>
        <td class="sticky-col col-lib"><?= e($l['libelle']) ?></td>
        <?php foreach ($mois as $m):
            $v = (float) ($l['mois'][$m['key']] ?? 0);
            $cellBug = isset($anomMois[$m['key']]);
        ?>
          <td class="num<?= $cellBug ? ' analytique-cell-bug' : '' ?>"
              <?= $cellBug ? ' title="Anomalie : vérifier le TRI (ex. VENTE en débit ou TRI vide)"' : '' ?>>
            <?= abs($v) < 0.005 ? '' : euro($v) ?>
          </td>
        <?php endforeach; ?>
        <td class="num">
          <strong><?= euro((float) $l['total']) ?></strong>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="sticky-col col-tri"><strong>Totaux</strong></td>
        <td class="sticky-col col-lib"></td>
        <?php foreach ($mois as $m):
            $key = (string) $m['key'];
            $t = (float) ($totauxMois[$key] ?? 0);
            $bq = (float) ($banqueMois[$key] ?? 0);
            $ecart = abs($t - $bq) >= 0.02;
            $title = $ecart
                ? 'Banque (débits − crédits) : ' . number_format($bq, 2, ',', ' ')
                    . ' € — écart ' . number_format($t - $bq, 2, ',', ' ') . ' €'
                : '= banque débits − crédits (' . number_format($bq, 2, ',', ' ') . ' €)';
        ?>
          <td class="num<?= $ecart ? ' analytique-ecart' : '' ?>"
              title="<?= e($title) ?>">
            <?= abs($t) < 0.005 ? '' : euro($t) ?>
          </td>
        <?php endforeach; ?>
        <td class="num"></td>
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
