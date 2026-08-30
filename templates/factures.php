<?php
declare(strict_types=1);
/** @var list<array> $factures */
/** @var array|null $exercice */
/** @var array<int, array{statut:string, mar:bool, auto_statut:bool, auto_mar:bool}> $metaFactures */
/** @var array|null $recap */
ob_start();

$metaFactures = $metaFactures ?? [];
$recap = $recap ?? null;
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
$csrf = csrf_token();
$saveUrl = page_url('factures');
?>
<div class="planning-top factures-top">
  <h1>Facturation</h1>
</div>
<?php if (!$exercice): ?>
  <p class="lead">Aucun exercice pour la date du jour. Voir <a href="<?= e(page_url('exercices')) ?>">Exercices</a>.</p>
<?php endif; ?>

<?php if (!$exercice): ?>
  <div class="empty">Impossible de saisir une facture sans exercice en cours.</div>
<?php else: ?>

<?php if ($recap): ?>
<div class="ca-recap" id="facturation-recap" aria-label="Récapitulatif facturation">
  <div class="ca-recap-ex">
    <div class="ca-recap-rail" aria-hidden="true"><span>exercice en cours</span></div>
    <table class="ca-recap-table">
      <tbody>
        <?php foreach ($recap['declarer'] as $d): ?>
        <tr data-recap-declarer="<?= (int) $d['annee'] ?>">
          <th scope="row"><?= (int) $d['annee'] ?> CA à déclarer</th>
          <td class="num" data-recap-val="ca"><?= euro((float) $d['ca']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="ca-recap-impayes">
          <th scope="row">Impayés</th>
          <td class="num" data-recap-val="impayes"><?= abs((float) $recap['impayes']) < 0.005 ? '— €' : euro((float) $recap['impayes']) ?></td>
        </tr>
        <tr class="ca-recap-encaisse">
          <th scope="row">CA Encaissé</th>
          <td class="num" data-recap-val="encaisse"><?= euro((float) $recap['ca_encaisse']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="ca-recap-sum">
    <div class="ca-recap-rail ca-recap-rail-sum" aria-hidden="true"><span>RÉCAPITULATIF</span></div>
    <table class="ca-recap-table">
      <tbody>
        <?php foreach ($recap['lignes'] as $i => $l): ?>
        <tr class="ca-recap-ht" data-recap-ht="<?= (int) $l['annee'] ?>">
          <th scope="row">CA HT <?= (int) $l['annee'] ?></th>
          <td class="num" colspan="2" data-recap-val="ca_ht"><?= euro((float) $l['ca_ht']) ?></td>
        </tr>
        <tr class="ca-recap-mar" data-recap-mar="<?= (int) $l['annee'] ?>">
          <th scope="row">CA MAR <?= (int) $l['annee'] ?></th>
          <td class="num" data-recap-val="ca_mar"><?= euro((float) $l['ca_mar']) ?></td>
          <td class="num ca-recap-pct" data-recap-val="pct_mar">
            <?php if ($l['pct_mar'] !== null): ?>
              <?= number_format((float) $l['pct_mar'] * 100, 2, ',', ' ') ?> %
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($i < count($recap['lignes']) - 1): ?>
        <tr class="ca-recap-spacer"><td colspan="3"></td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="ca-recap-legend" aria-label="Légende">
    <span class="leg leg-facture">V vert = pas payé</span>
    <span class="leg leg-paye">B bleu = payé</span>
    <span class="leg leg-litige">J jaune = impayé</span>
    <span class="leg leg-mar" title="Ligne rosée = mission MAR">MAR = fond rose</span>
  </div>
</div>
<?php else: ?>
  <div class="legend legend-stack">
    <span class="leg leg-facture">V vert = pas payé</span>
    <span class="leg leg-paye">B bleu = payé</span>
    <span class="leg leg-litige">J jaune = impayé</span>
    <span class="leg leg-mar" title="Ligne rosée = mission MAR">MAR = fond rose</span>
  </div>
<?php endif; ?>

<form method="post" action="<?= e($saveUrl) ?>" id="<?= e($formId) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="creer">
</form>
<table class="data factures-grid" id="factures-grid"
       data-save-url="<?= e($saveUrl) ?>"
       data-csrf="<?= e($csrf) ?>">
  <thead>
    <tr>
      <th>Date</th>
      <th>N°</th>
      <th>Client</th>
      <th class="num">HT</th>
      <th class="num">Taux TVA</th>
      <th class="num">TVA</th>
      <th class="num">TTC</th>
      <th title="MAR · Stripe · —">Type</th>
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
        <select class="cell-tag-fac" form="<?= e($formId) ?>" name="tag" aria-label="Type">
          <option value="mar">MAR</option>
          <option value="stripe">STRIPE</option>
          <option value="" selected>—</option>
        </select>
      </td>
      <td>
        <button class="btn" form="<?= e($formId) ?>" type="submit">Ajouter</button>
      </td>
    </tr>
  <?php if (!$factures): ?>
    <tr><td colspan="9" class="muted">Aucune facture pour cet exercice — saisissez la première ci-dessus.</td></tr>
  <?php else: foreach ($factures as $f):
      $fid = (int) $f['id'];
      $ht = (float) $f['ht'];
      $meta = $metaFactures[$fid] ?? ['statut' => 'facture', 'mar' => false, 'auto_statut' => true, 'auto_mar' => true];
      $coul = $meta['statut'];
      if (!in_array($coul, ['facture', 'litige', 'paye'], true)) {
          $coul = 'facture';
      }
      $isMar = !empty($meta['mar']);
      $canalNorm = strtolower(trim((string) ($f['canal'] ?? '')));
      if ($isMar) {
          $tagSelect = 'mar';
      } elseif ($canalNorm === 'stripe') {
          $tagSelect = 'stripe';
      } else {
          $tagSelect = '';
      }
      $titreCoul = match ($coul) {
          'paye' => 'Payé (planning)',
          'litige' => 'Impayé (planning)',
          default => 'Pas encore payé (planning)',
      };
      $rowClass = 'row-coul-' . $coul . ($isMar ? ' row-mar' : '');
  ?>
    <tr class="<?= e($rowClass) ?>" data-facture-id="<?= $fid ?>" title="<?= e($titreCoul . ($isMar ? ' · MAR' : '')) ?>">
      <td><?= date_fr($f['date_facture']) ?></td>
      <td class="facture-num">
        <span class="facture-num-val"><?= e($f['numero']) ?></span>
      </td>
      <td><?= e($f['client']) ?></td>
      <td class="num txt-<?= e($coul) ?> amt-coul"><?= euro($ht) ?></td>
      <td class="num muted"><?= number_format((float) $f['taux_tva'] * 100, 0, ',', ' ') ?> %</td>
      <td class="num txt-<?= e($coul) ?>"><?= euro((float) $f['tva']) ?></td>
      <td class="num txt-<?= e($coul) ?> amt-coul"><?= euro((float) $f['ttc']) ?></td>
      <td>
        <select class="cell-tag-fac" data-field="tag" aria-label="Type">
          <option value="mar" <?= $tagSelect === 'mar' ? 'selected' : '' ?>>MAR</option>
          <option value="stripe" <?= $tagSelect === 'stripe' ? 'selected' : '' ?>>STRIPE</option>
          <option value="" <?= $tagSelect === '' ? 'selected' : '' ?>>—</option>
        </select>
      </td>
      <td></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
<script>
(function () {
  function parseFr(v) {
    if (!v) return null;
    var n = parseFloat(String(v).replace(/[\s\u00a0\u202f]/g, '').replace(',', '.'));
    return isFinite(n) ? n : null;
  }
  function formatAmountInput(raw) {
    var s = String(raw == null ? '' : raw).replace(/[\s\u00a0\u202f]/g, '').trim();
    if (s === '' || s === '—' || s === '-') return '';
    s = s.replace(',', '.');
    var n = Number(s);
    if (!Number.isFinite(n)) return String(raw);
    var neg = n < 0;
    var abs = Math.abs(n);
    var fixed = (Math.round(abs * 100) / 100).toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (neg ? '−' : '') + parts[0] + ',' + parts[1];
  }
  function euro(n) {
    var neg = n < 0;
    var abs = Math.abs(n);
    var fixed = (Math.round(abs * 100) / 100).toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (neg ? '−' : '') + parts[0] + ',' + parts[1] + ' €';
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
  var htEl = document.getElementById('f-ht');
  if (htEl) {
    htEl.addEventListener('input', refresh);
    htEl.addEventListener('blur', function () {
      htEl.value = formatAmountInput(htEl.value);
      refresh();
    });
  }
  var tauxEl = document.getElementById('f-taux');
  if (tauxEl) tauxEl.addEventListener('input', refresh);
  refresh();

  var grid = document.getElementById('factures-grid');
  if (!grid) return;
  var saveUrl = grid.getAttribute('data-save-url') || '';
  var csrf = grid.getAttribute('data-csrf') || '';

  function applyMar(tr, isMar) {
    tr.classList.toggle('row-mar', !!isMar);
  }

  function euroDash(n) {
    if (n == null || !isFinite(n) || Math.abs(n) < 0.005) return '— €';
    return euro(n);
  }

  function pctFr(p) {
    if (p == null || !isFinite(p)) return '—';
    var n = Math.round(p * 10000) / 100;
    var fixed = n.toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return parts[0] + ',' + parts[1] + ' %';
  }

  function updateRecap(recap) {
    var root = document.getElementById('facturation-recap');
    if (!root || !recap) return;
    (recap.declarer || []).forEach(function (d) {
      var cell = root.querySelector('[data-recap-declarer="' + d.annee + '"] [data-recap-val="ca"]');
      if (cell) cell.textContent = euro(d.ca);
    });
    var imp = root.querySelector('[data-recap-val="impayes"]');
    if (imp) imp.textContent = euroDash(recap.impayes);
    var enc = root.querySelector('[data-recap-val="encaisse"]');
    if (enc) enc.textContent = euro(recap.ca_encaisse);
    (recap.lignes || []).forEach(function (l) {
      var ht = root.querySelector('[data-recap-ht="' + l.annee + '"] [data-recap-val="ca_ht"]');
      if (ht) ht.textContent = euro(l.ca_ht);
      var mar = root.querySelector('[data-recap-mar="' + l.annee + '"] [data-recap-val="ca_mar"]');
      if (mar) mar.textContent = euro(l.ca_mar);
      var pct = root.querySelector('[data-recap-mar="' + l.annee + '"] [data-recap-val="pct_mar"]');
      if (pct) pct.textContent = pctFr(l.pct_mar);
    });
  }

  function saveMeta(tr) {
    var fid = tr.getAttribute('data-facture-id');
    if (!fid) return;
    var tagSel = tr.querySelector('[data-field="tag"]');
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_meta_facture');
    fd.append('facture_id', fid);
    fd.append('tag', tagSel ? tagSel.value : '');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.meta) return;
        applyMar(tr, !!data.meta.mar);
        if (data.recap) updateRecap(data.recap);
      })
      .catch(function () {});
  }

  grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLSelectElement)) return;
    if (!t.matches('[data-field="tag"]')) return;
    var tr = t.closest('tr[data-facture-id]');
    if (!tr) return;
    applyMar(tr, t.value === 'mar');
    saveMeta(tr);
  });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Facturation';
$page = 'factures';
require dirname(__DIR__) . '/templates/layout.php';
