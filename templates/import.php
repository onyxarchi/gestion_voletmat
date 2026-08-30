<?php
declare(strict_types=1);
/** @var array|null $preview */
/** @var int|null $import_id */
ob_start();
?>
<h1>Import bancaire</h1>
<p class="lead">
  Relevé CIC en <strong>Excel (.xlsx)</strong> ou <strong>PDF</strong>.
  Prévisualisation obligatoire : recouper les <strong>débits / crédits</strong> et le
  <strong>solde</strong> avec le relevé avant validation. Les montants devise du libellé
  (ex. 10,00&nbsp;USD) ne sont jamais pris pour le montant €.
</p>

<div class="panel">
  <form method="post" action="<?= e(page_url('import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label for="fichier">Fichier relevé CIC (.xlsx ou .pdf)</label>
    <input type="file" id="fichier" name="fichier" accept=".xlsx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    <div class="form-actions">
      <button class="btn" type="submit">Prévisualiser</button>
      <a class="btn secondary" href="<?= e(page_url('banque')) ?>">Retour Banque</a>
    </div>
  </form>
</div>

<?php if ($preview): ?>
  <h2>Prévisualisation — <?= e((string) ($preview['compte'] ?? 'relevé')) ?></h2>

  <?php
    $ok = 0; $doublons = 0; $incertains = 0;
    foreach ($preview['lignes'] as $l) {
        if ($l['statut'] === 'ok') {
            $ok++;
        } elseif ($l['statut'] === 'doublon') {
            $doublons++;
        } else {
            $incertains++;
        }
    }
    $ecart = $preview['ecart_solde'] ?? null;
    $oublis = (int) ($preview['oublis_base'] ?? 0);
    $soldeOk = $ecart === null || abs((float) $ecart) < 0.02;
    $periode = $preview['periode'] ?? null;
  ?>

  <div class="cards">
    <div class="card"><div class="label">Lignes OK</div><div class="value"><?= $ok ?></div></div>
    <div class="card"><div class="label">Doublons</div><div class="value"><?= $doublons ?></div></div>
    <div class="card"><div class="label">Incertaines</div><div class="value"><?= $incertains ?></div></div>
    <div class="card"><div class="label">Oublis (base ≠ relevé)</div><div class="value"><?= $oublis ?></div></div>
  </div>

  <div class="import-controle <?= (!$soldeOk || $oublis > 0) ? 'import-controle-warn' : 'import-controle-ok' ?>">
    <?php if ($periode): ?>
      <p>Période du relevé : <strong><?= date_fr($periode[0]) ?></strong> → <strong><?= date_fr($periode[1]) ?></strong></p>
    <?php endif; ?>
    <?php if (($preview['solde_initial'] ?? null) !== null || ($preview['solde_final'] ?? null) !== null): ?>
      <p>
        Solde initial<?= !empty($preview['solde_initial_deduit']) ? ' (déduit)' : '' ?> :
        <strong><?= ($preview['solde_initial'] ?? null) !== null ? euro((float) $preview['solde_initial']) : '—' ?></strong>
        · Solde final :
        <strong><?= ($preview['solde_final'] ?? null) !== null ? euro((float) $preview['solde_final']) : '—' ?></strong>
      </p>
    <?php endif; ?>
    <?php if (($preview['sum_debit'] ?? null) !== null): ?>
      <p>
        Sous-total relevé (totaux mouvements) : débits
        <strong><?= euro(abs((float) $preview['sum_debit'])) ?></strong>,
        crédits <strong><?= euro(abs((float) ($preview['sum_credit'] ?? 0))) ?></strong>
        — doivent coller au bas du relevé CIC.
      </p>
    <?php endif; ?>
    <?php if ($ecart !== null): ?>
      <?php if ($soldeOk): ?>
        <p class="ok-msg">Recoupement solde : OK (écart <?= euro((float) $ecart) ?>).</p>
      <?php else: ?>
        <p class="warn-msg">Écart de solde : <strong><?= euro((float) $ecart) ?></strong> — ne pas valider tant que les montants ne collent pas au relevé.</p>
      <?php endif; ?>
    <?php elseif (!empty($preview['solde_initial_deduit'])): ?>
      <p class="muted">Pas de solde initial dans le fichier CIC : contrôle d’équilibre non vérifiable (solde initial déduit du final).</p>
    <?php endif; ?>
    <?php if ($oublis > 0): ?>
      <p class="warn-msg">
        <?= $oublis ?> opération(s) déjà en base sur cette période <strong>absente(s) de ce relevé</strong>
        (possible oubli dans le fichier, ou relevé partiel).
      </p>
    <?php elseif ($periode): ?>
      <p class="ok-msg">Aucune opération en base absente de ce relevé sur la période.</p>
    <?php endif; ?>
    <?php if ($doublons > 0): ?>
      <p class="muted"><?= $doublons ?> doublon(s) ignorés à la validation (déjà en banque).</p>
    <?php endif; ?>
  </div>

  <table class="data">
    <thead>
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Libellé</th>
        <th class="num">Débit</th>
        <th class="num">Crédit</th>
        <th>Statut</th>
        <th>Motif</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $sumPrevD = 0.0;
      $sumPrevC = 0.0;
      foreach ($preview['lignes'] as $l):
          if ($l['debit'] !== null) {
              $sumPrevD += (float) $l['debit'];
          }
          if ($l['credit'] !== null) {
              $sumPrevC += (float) $l['credit'];
          }
    ?>
      <tr>
        <td><?= (int) $l['ligne_no'] ?></td>
        <td><?= date_fr($l['date_operation'] ?? null) ?></td>
        <td><?= e((string) ($l['libelle'] ?? '')) ?></td>
        <td class="num"><?= $l['debit'] !== null ? euro((float) $l['debit']) : '' ?></td>
        <td class="num"><?= $l['credit'] !== null ? euro((float) $l['credit']) : '' ?></td>
        <td><span class="pill <?= e($l['statut']) ?>"><?= e($l['statut']) ?></span></td>
        <td class="muted"><?= e((string) ($l['motif'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="import-sous-total">
        <td colspan="3">Sous-total = totaux mouvements du relevé CIC</td>
        <td class="num"><?= euro(abs($sumPrevD)) ?></td>
        <td class="num"><?= euro(abs($sumPrevC)) ?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>

  <?php if ($import_id && $ok > 0): ?>
  <form method="post" action="<?= e(page_url('import')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="valider">
    <input type="hidden" name="import_id" value="<?= (int) $import_id ?>">
    <button class="btn" type="submit">Valider les <?= $ok ?> ligne(s) OK (ignorer doublons / incertains)</button>
  </form>
  <?php elseif ($import_id && $ok === 0): ?>
    <p class="lead">Rien à valider — uniquement des doublons ou lignes incertaines.</p>
  <?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Import';
$page = 'import';
require dirname(__DIR__) . '/templates/layout.php';
