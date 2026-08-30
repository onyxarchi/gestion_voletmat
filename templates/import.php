<?php
declare(strict_types=1);
/** @var array|null $preview */
/** @var int|null $import_id */
ob_start();
?>
<h1>Import bancaire</h1>
<p class="lead">
  Excel CIC (.xlsx), CSV ou PDF. Prévisualisation obligatoire. Aucun montant inventé :
  ligne douteuse = « incertain » ou rejetée.
</p>

<div class="panel">
  <form method="post" action="<?= e(page_url('import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label for="fichier">Fichier relevé (CIC Excel recommandé)</label>
    <input type="file" id="fichier" name="fichier" accept=".xlsx,.xls,.csv,.pdf" required>
    <div class="form-actions">
      <button class="btn" type="submit">Prévisualiser</button>
    </div>
  </form>
</div>

<?php if ($preview): ?>
  <h2>Prévisualisation — <?= e((string) ($preview['compte'] ?? 'relevé')) ?></h2>
  <?php if ($preview['solde_final'] !== null): ?>
    <p class="lead">Solde indiqué dans le fichier : <?= euro((float) $preview['solde_final']) ?> (information, non utilisée pour créer des écritures).</p>
  <?php endif; ?>

  <?php
    $ok = 0; $doublons = 0; $incertains = 0;
    foreach ($preview['lignes'] as $l) {
        if ($l['statut'] === 'ok') $ok++;
        elseif ($l['statut'] === 'doublon') $doublons++;
        else $incertains++;
    }
  ?>
  <div class="cards">
    <div class="card"><div class="label">Lignes OK</div><div class="value"><?= $ok ?></div></div>
    <div class="card"><div class="label">Doublons</div><div class="value"><?= $doublons ?></div></div>
    <div class="card"><div class="label">Incertaines</div><div class="value"><?= $incertains ?></div></div>
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
    <?php foreach ($preview['lignes'] as $l): ?>
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
  </table>

  <?php if ($import_id && $ok > 0): ?>
  <form method="post" action="<?= e(page_url('import')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="valider">
    <input type="hidden" name="import_id" value="<?= (int) $import_id ?>">
    <button class="btn" type="submit">Valider les <?= $ok ?> ligne(s) OK (ignorer doublons / incertains)</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Import';
$page = 'import';
require dirname(__DIR__) . '/templates/layout.php';
