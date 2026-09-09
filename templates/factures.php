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
  <p class="lead">Aucun exercice pour la date du jour.</p>
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
        <input class="cell-input num" form="<?= e($formId) ?>" type="text" name="ht" id="f-ht" placeholder="0,00 €" inputmode="decimal" required>
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
      $tauxPct = (int) round((float) $f['taux_tva'] * 100);
  ?>
    <tr class="<?= e($rowClass) ?>"
        data-facture-id="<?= $fid ?>"
        data-date="<?= e((string) $f['date_facture']) ?>"
        data-numero="<?= e((string) $f['numero']) ?>"
        data-client="<?= e((string) $f['client']) ?>"
        data-ht="<?= e(number_format($ht, 2, '.', '')) ?>"
        data-taux="<?= $tauxPct ?>"
        data-tag="<?= e($tagSelect) ?>"
        title="<?= e($titreCoul . ($isMar ? ' · MAR' : '')) ?>">
      <td data-col="date"><?= date_fr($f['date_facture']) ?></td>
      <td class="facture-num" data-col="numero">
        <span class="facture-num-val"><?= e($f['numero']) ?></span>
      </td>
      <td data-col="client"><?= e($f['client']) ?></td>
      <td class="num txt-<?= e($coul) ?> amt-coul" data-col="ht"><?= euro($ht) ?></td>
      <td class="num muted" data-col="taux"><?= $tauxPct ?> %</td>
      <td class="num txt-<?= e($coul) ?>" data-col="tva"><?= euro((float) $f['tva']) ?></td>
      <td class="num txt-<?= e($coul) ?> amt-coul" data-col="ttc"><?= euro((float) $f['ttc']) ?></td>
      <td data-col="tag">
        <select class="cell-tag-fac" data-field="tag" aria-label="Type">
          <option value="mar" <?= $tagSelect === 'mar' ? 'selected' : '' ?>>MAR</option>
          <option value="stripe" <?= $tagSelect === 'stripe' ? 'selected' : '' ?>>STRIPE</option>
          <option value="" <?= $tagSelect === '' ? 'selected' : '' ?>>—</option>
        </select>
      </td>
      <td class="factures-actions">
        <button type="button" class="btn-ico btn-edit-ligne" title="Modifier" aria-label="Modifier">✎</button>
        <button type="button" class="btn-ico btn-del-ligne" title="Supprimer" aria-label="Supprimer">×</button>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
<script>
(function () {
  function parseFr(v) {
    if (!v) return null;
    var n = parseFloat(String(v).replace(/[\s\u00a0\u202f€]/g, '').replace(',', '.'));
    return isFinite(n) ? n : null;
  }
  function formatAmountInput(raw) {
    var s = String(raw == null ? '' : raw).replace(/[\s\u00a0\u202f€]/g, '').trim();
    if (s === '' || s === '—' || s === '-') return '';
    s = s.replace(',', '.');
    var n = Number(s);
    if (!Number.isFinite(n)) return String(raw);
    var neg = n < 0;
    var abs = Math.abs(n);
    var fixed = (Math.round(abs * 100) / 100).toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (neg ? '−' : '') + parts[0] + ',' + parts[1] + ' €';
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
  var editingTr = null;

  function applyMar(tr, isMar) {
    tr.classList.toggle('row-mar', !!isMar);
  }

  function coulClass(tr) {
    var m = (tr.className || '').match(/row-coul-(\w+)/);
    return m ? m[1] : 'facture';
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

  function tagOptionsHtml(selected) {
    return '<option value="mar"' + (selected === 'mar' ? ' selected' : '') + '>MAR</option>' +
      '<option value="stripe"' + (selected === 'stripe' ? ' selected' : '') + '>STRIPE</option>' +
      '<option value=""' + (selected === '' ? ' selected' : '') + '>—</option>';
  }

  function refreshEditPreview(tr) {
    var htInp = tr.querySelector('[data-edit="ht"]');
    var tauxInp = tr.querySelector('[data-edit="taux"]');
    var tvaCell = tr.querySelector('[data-col="tva"]');
    var ttcCell = tr.querySelector('[data-col="ttc"]');
    if (!htInp || !tauxInp || !tvaCell || !ttcCell) return;
    var ht = parseFr(htInp.value);
    var taux = parseFr(tauxInp.value);
    if (ht === null || taux === null) {
      tvaCell.textContent = '—';
      ttcCell.textContent = '—';
      return;
    }
    var tva = Math.round(ht * (taux / 100) * 100) / 100;
    tvaCell.textContent = euro(tva);
    ttcCell.textContent = euro(Math.round((ht + tva) * 100) / 100);
  }

  function renderView(tr, f, meta) {
    var coul = (meta && meta.statut) || coulClass(tr);
    if (!in_array_coul(coul)) coul = 'facture';
    var isMar = !!(meta && meta.mar);
    var tag = (f && f.tag != null) ? f.tag : (tr.getAttribute('data-tag') || '');
    tr.className = 'row-coul-' + coul + (isMar ? ' row-mar' : '');
    tr.setAttribute('data-date', f.date_facture);
    tr.setAttribute('data-numero', f.numero);
    tr.setAttribute('data-client', f.client);
    tr.setAttribute('data-ht', String(f.ht));
    tr.setAttribute('data-taux', String(f.taux_pct));
    tr.setAttribute('data-tag', tag);

    tr.querySelector('[data-col="date"]').textContent = f.date_fr;
    tr.querySelector('[data-col="numero"]').innerHTML =
      '<span class="facture-num-val">' + escapeHtml(f.numero) + '</span>';
    tr.querySelector('[data-col="client"]').textContent = f.client;
    var htCell = tr.querySelector('[data-col="ht"]');
    htCell.className = 'num txt-' + coul + ' amt-coul';
    htCell.setAttribute('data-col', 'ht');
    htCell.textContent = f.ht_label;
    tr.querySelector('[data-col="taux"]').textContent = f.taux_pct + ' %';
    var tvaCell = tr.querySelector('[data-col="tva"]');
    tvaCell.className = 'num txt-' + coul;
    tvaCell.setAttribute('data-col', 'tva');
    tvaCell.textContent = f.tva_label;
    var ttcCell = tr.querySelector('[data-col="ttc"]');
    ttcCell.className = 'num txt-' + coul + ' amt-coul';
    ttcCell.setAttribute('data-col', 'ttc');
    ttcCell.textContent = f.ttc_label;
    tr.querySelector('[data-col="tag"]').innerHTML =
      '<select class="cell-tag-fac" data-field="tag" aria-label="Type">' +
      tagOptionsHtml(tag) + '</select>';
    tr.querySelector('.factures-actions').innerHTML =
      '<button type="button" class="btn-ico btn-edit-ligne" title="Modifier" aria-label="Modifier">✎</button>' +
      '<button type="button" class="btn-ico btn-del-ligne" title="Supprimer" aria-label="Supprimer">×</button>';
  }

  function in_array_coul(c) {
    return c === 'facture' || c === 'litige' || c === 'paye';
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function cancelEdit(tr) {
    if (!tr || !tr.classList.contains('row-editing')) return;
    var f = {
      date_facture: tr.getAttribute('data-date') || '',
      date_fr: formatDateFr(tr.getAttribute('data-date') || ''),
      numero: tr.getAttribute('data-numero') || '',
      client: tr.getAttribute('data-client') || '',
      ht: parseFloat(tr.getAttribute('data-ht') || '0') || 0,
      ht_label: euro(parseFloat(tr.getAttribute('data-ht') || '0') || 0),
      taux_pct: parseInt(tr.getAttribute('data-taux') || '20', 10) || 20,
      tva_label: tr.querySelector('[data-col="tva"]') ? '' : '',
      ttc_label: '',
      tag: tr.getAttribute('data-tag') || '',
    };
    var ht = f.ht;
    var taux = f.taux_pct;
    var tva = Math.round(ht * (taux / 100) * 100) / 100;
    f.tva_label = euro(tva);
    f.ttc_label = euro(Math.round((ht + tva) * 100) / 100);
    tr.classList.remove('row-editing');
    if (editingTr === tr) editingTr = null;
    renderView(tr, f, { statut: coulClass(tr), mar: tr.classList.contains('row-mar') });
  }

  function formatDateFr(iso) {
    if (!iso || iso.length < 10) return iso || '';
    return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
  }

  function enterEdit(tr) {
    if (editingTr && editingTr !== tr) cancelEdit(editingTr);
    if (tr.classList.contains('row-editing')) return;
    editingTr = tr;
    tr.classList.add('row-editing');
    var date = tr.getAttribute('data-date') || '';
    var numero = tr.getAttribute('data-numero') || '';
    var client = tr.getAttribute('data-client') || '';
    var ht = tr.getAttribute('data-ht') || '';
    var taux = tr.getAttribute('data-taux') || '20';
    var tag = tr.getAttribute('data-tag') || '';

    tr.querySelector('[data-col="date"]').innerHTML =
      '<input class="cell-input" type="date" data-edit="date" value="' + escapeHtml(date) + '">';
    tr.querySelector('[data-col="numero"]').innerHTML =
      '<input class="cell-input" type="text" data-edit="numero" value="' + escapeHtml(numero) + '" autocomplete="off">';
    tr.querySelector('[data-col="client"]').innerHTML =
      '<input class="cell-input" type="text" data-edit="client" value="' + escapeHtml(client) + '" autocomplete="organization">';
    var htCell = tr.querySelector('[data-col="ht"]');
    htCell.className = 'num';
    htCell.setAttribute('data-col', 'ht');
    htCell.innerHTML =
      '<input class="cell-input num" type="text" data-edit="ht" value="' +
      escapeHtml(formatAmountInput(ht)) + '" inputmode="decimal">';
    tr.querySelector('[data-col="taux"]').innerHTML =
      '<input class="cell-input num" type="text" data-edit="taux" value="' +
      escapeHtml(String(taux)) + '" inputmode="decimal" title="Taux en %">';
    var tvaCell = tr.querySelector('[data-col="tva"]');
    tvaCell.className = 'num muted';
    tvaCell.setAttribute('data-col', 'tva');
    var ttcCell = tr.querySelector('[data-col="ttc"]');
    ttcCell.className = 'num muted';
    ttcCell.setAttribute('data-col', 'ttc');
    tr.querySelector('[data-col="tag"]').innerHTML =
      '<select class="cell-tag-fac" data-edit="tag" aria-label="Type">' + tagOptionsHtml(tag) + '</select>';
    tr.querySelector('.factures-actions').innerHTML =
      '<button type="button" class="btn-ico btn-save-ligne" title="Enregistrer" aria-label="Enregistrer">✓</button>' +
      '<button type="button" class="btn-ico btn-cancel-ligne" title="Annuler" aria-label="Annuler">↩</button>';

    refreshEditPreview(tr);
    var focusEl = tr.querySelector('[data-edit="numero"]');
    if (focusEl) {
      try { focusEl.focus({ preventScroll: true }); } catch (e) { focusEl.focus(); }
      if (focusEl.select) focusEl.select();
    }
  }

  function saveEdit(tr) {
    var fid = tr.getAttribute('data-facture-id');
    if (!fid) return;
    var dateEl = tr.querySelector('[data-edit="date"]');
    var numEl = tr.querySelector('[data-edit="numero"]');
    var clientEl = tr.querySelector('[data-edit="client"]');
    var htEl2 = tr.querySelector('[data-edit="ht"]');
    var tauxEl2 = tr.querySelector('[data-edit="taux"]');
    var tagEl = tr.querySelector('[data-edit="tag"]');
    var date = dateEl ? dateEl.value : '';
    var numero = numEl ? numEl.value.trim() : '';
    var client = clientEl ? clientEl.value.trim() : '';
    var ht = htEl2 ? parseFr(htEl2.value) : null;
    var taux = tauxEl2 ? parseFr(tauxEl2.value) : null;
    var tag = tagEl ? tagEl.value : '';
    if (!date || !numero || !client || ht === null || taux === null) {
      window.alert('Saisie incomplète : date, n°, client, HT et taux TVA sont obligatoires.');
      return;
    }
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_facture');
    fd.append('facture_id', fid);
    fd.append('date_facture', date);
    fd.append('numero', numero);
    fd.append('client', client);
    fd.append('ht', String(ht).replace('.', ','));
    fd.append('taux_tva', String(taux).replace('.', ','));
    fd.append('tag', tag);
    tr.querySelectorAll('.btn-ico').forEach(function (b) { b.disabled = true; });
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.facture) {
          tr.querySelectorAll('.btn-ico').forEach(function (b) { b.disabled = false; });
          window.alert((data && data.erreur) || 'Enregistrement impossible.');
          return;
        }
        tr.classList.remove('row-editing');
        if (editingTr === tr) editingTr = null;
        renderView(tr, data.facture, data.meta || null);
        applyMar(tr, !!(data.meta && data.meta.mar));
        if (data.recap) updateRecap(data.recap);
      })
      .catch(function () {
        tr.querySelectorAll('.btn-ico').forEach(function (b) { b.disabled = false; });
        window.alert('Erreur réseau.');
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
        tr.setAttribute('data-tag', tagSel ? tagSel.value : '');
        if (data.recap) updateRecap(data.recap);
      })
      .catch(function () {});
  }

  grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLSelectElement)) return;
    if (!t.matches('[data-field="tag"]')) return;
    var tr = t.closest('tr[data-facture-id]');
    if (!tr || tr.classList.contains('row-editing')) return;
    applyMar(tr, t.value === 'mar');
    saveMeta(tr);
  });

  grid.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    var tr = t.closest('tr.row-editing');
    if (!tr) return;
    if (t.matches('[data-edit="ht"], [data-edit="taux"]')) refreshEditPreview(tr);
  });

  grid.addEventListener('focusout', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.matches('[data-edit="ht"]')) return;
    t.value = formatAmountInput(t.value);
    var tr = t.closest('tr.row-editing');
    if (tr) refreshEditPreview(tr);
  });

  grid.addEventListener('keydown', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr.row-editing');
    if (!tr) return;
    if (ev.key === 'Escape') {
      ev.preventDefault();
      cancelEdit(tr);
    } else if (ev.key === 'Enter' && ev.target.matches('input')) {
      ev.preventDefault();
      saveEdit(tr);
    }
  });

  grid.addEventListener('click', function (ev) {
    var editBtn = ev.target.closest('.btn-edit-ligne');
    if (editBtn) {
      var trE = editBtn.closest('tr[data-facture-id]');
      if (trE) enterEdit(trE);
      return;
    }
    var saveBtn = ev.target.closest('.btn-save-ligne');
    if (saveBtn) {
      var trS = saveBtn.closest('tr[data-facture-id]');
      if (trS) saveEdit(trS);
      return;
    }
    var cancelBtn = ev.target.closest('.btn-cancel-ligne');
    if (cancelBtn) {
      var trC = cancelBtn.closest('tr[data-facture-id]');
      if (trC) cancelEdit(trC);
      return;
    }
    var btn = ev.target.closest('.btn-del-ligne');
    if (!btn) return;
    var tr = btn.closest('tr[data-facture-id]');
    if (!tr) return;
    var fid = tr.getAttribute('data-facture-id');
    if (!fid) return;
    var num = tr.getAttribute('data-numero') || '';
    var client = tr.getAttribute('data-client') || '';
    var label = (num + (client ? ' — ' + client : '')).trim() || 'cette facture';
    if (!window.confirm('Supprimer « ' + label + ' » ?\nCette action est définitive.')) {
      return;
    }
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'suppr_facture');
    fd.append('facture_id', fid);
    btn.disabled = true;
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          btn.disabled = false;
          window.alert((data && data.erreur) || 'Suppression impossible.');
          return;
        }
        if (editingTr === tr) editingTr = null;
        tr.remove();
        if (data.recap) updateRecap(data.recap);
      })
      .catch(function () {
        btn.disabled = false;
        window.alert('Erreur réseau.');
      });
  });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Facturation';
$page = 'factures';
require dirname(__DIR__) . '/templates/layout.php';
