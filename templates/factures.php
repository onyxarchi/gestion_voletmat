<?php
declare(strict_types=1);
/** @var list<array> $factures */
/** @var array|null $exercice */
ob_start();

$suggestNumero = '';
if ($factures) {
    foreach ($factures as $f) {
        if (preg_match('/^(F-\d{4}-\d{2}-)(\d+)$/i', (string) $f['numero'], $m)) {
            $suggestNumero = $m[1] . str_pad((string) ((int) $m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
            break;
        }
    }
}
$today = date('Y-m-d');
$formId = 'form-nouvelle-facture';
?>
<h1>Factures</h1>
<?php if ($exercice): ?>
  <p class="lead">
    Exercice en cours : <strong><?= e($exercice['libelle']) ?></strong>
    (<?= date_fr($exercice['date_debut']) ?> → <?= date_fr($exercice['date_fin']) ?>).
    <strong>Canal</strong> = mode d’encaissement (ex. Stripe) — laissez vide pour un règlement classique.
  </p>
<?php else: ?>
  <p class="lead">Aucun exercice pour la date du jour. Voir <a href="<?= e(page_url('exercices')) ?>">Exercices</a>.</p>
<?php endif; ?>

<?php if (!$exercice): ?>
  <div class="empty">Impossible de saisir une facture sans exercice en cours.</div>
<?php else: ?>
<form method="post" action="<?= e(page_url('factures')) ?>" id="<?= e($formId) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="creer">
</form>
<table class="data">
  <thead>
    <tr>
      <th>Date</th>
      <th>N°</th>
      <th>Client</th>
      <th class="num">HT</th>
      <th class="num">Taux TVA</th>
      <th class="num">TVA</th>
      <th class="num">TTC</th>
      <th title="Mode d’encaissement (Stripe, etc.)">Canal</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <tr class="row-new">
      <td>
        <input class="cell-input" form="<?= e($formId) ?>" type="date" name="date_facture" value="<?= e($today) ?>" required>
      </td>
      <td>
        <input class="cell-input" form="<?= e($formId) ?>" type="text" name="numero" value="<?= e($suggestNumero) ?>" placeholder="F-2026-08-121" required autocomplete="off">
      </td>
      <td>
        <input class="cell-input" form="<?= e($formId) ?>" type="text" name="client" placeholder="Client" required autocomplete="organization">
      </td>
      <td class="num">
        <input class="cell-input num" form="<?= e($formId) ?>" type="text" name="ht" id="f-ht" placeholder="0,00" inputmode="decimal" required>
      </td>
      <td class="num">
        <input class="cell-input num" form="<?= e($formId) ?>" type="text" name="taux_tva" id="f-taux" value="20" inputmode="decimal" required title="Taux en %">
      </td>
      <td class="num muted" id="f-tva-preview">—</td>
      <td class="num muted" id="f-ttc-preview">—</td>
      <td>
        <input class="cell-input" form="<?= e($formId) ?>" type="text" name="canal" placeholder="Stripe…" list="canaux-connus" autocomplete="off">
        <datalist id="canaux-connus">
          <option value="Stripe">
          <option value="Virement">
          <option value="Chèque">
        </datalist>
      </td>
      <td>
        <button class="btn" form="<?= e($formId) ?>" type="submit">Ajouter</button>
      </td>
    </tr>
  <?php if (!$factures): ?>
    <tr><td colspan="9" class="muted">Aucune facture pour cet exercice — saisissez la première ci-dessus.</td></tr>
  <?php else: foreach ($factures as $f): ?>
    <tr>
      <td><?= date_fr($f['date_facture']) ?></td>
      <td><?= e($f['numero']) ?></td>
      <td><?= e($f['client']) ?></td>
      <td class="num"><?= euro((float) $f['ht']) ?></td>
      <td class="num muted"><?= number_format((float) $f['taux_tva'] * 100, 0, ',', ' ') ?> %</td>
      <td class="num"><?= euro((float) $f['tva']) ?></td>
      <td class="num"><?= euro((float) $f['ttc']) ?></td>
      <td class="muted"><?= e((string) ($f['canal'] ?? '')) ?></td>
      <td></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
<script>
(function () {
  function parseFr(v) {
    if (!v) return null;
    var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
    return isFinite(n) ? n : null;
  }
  function euro(n) {
    return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  function refresh() {
    var ht = parseFr(document.getElementById('f-ht').value);
    var taux = parseFr(document.getElementById('f-taux').value);
    var tvaEl = document.getElementById('f-tva-preview');
    var ttcEl = document.getElementById('f-ttc-preview');
    if (ht === null || taux === null) {
      tvaEl.textContent = '—';
      ttcEl.textContent = '—';
      return;
    }
    var tva = Math.round(ht * (taux / 100) * 100) / 100;
    tvaEl.textContent = euro(tva);
    ttcEl.textContent = euro(Math.round((ht + tva) * 100) / 100);
  }
  ['f-ht', 'f-taux'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', refresh);
  });
  refresh();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Factures';
$page = 'factures';
require dirname(__DIR__) . '/templates/layout.php';
