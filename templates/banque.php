<?php
declare(strict_types=1);
/** @var list<array> $operations */
/** @var array|null $exercice */
/** @var array $banqueStats */
/** @var list<array{code:string,libelle:string}> $categories */
ob_start();

$operations = $operations ?? [];
$banqueStats = $banqueStats ?? [
    'nb' => 0,
    'debits' => 0.0,
    'credits' => 0.0,
    'ventes' => 0.0,
];
$categories = $categories ?? [];
$csrf = csrf_token();
$saveUrl = page_url('banque');
$moisList = [];
foreach ($operations as $o) {
    $am = (string) ($o['annee_mois'] ?? '');
    if ($am !== '') {
        $moisList[$am] = true;
    }
}
$moisList = array_keys($moisList);
rsort($moisList);
?>
<h1>Banque</h1>

<div class="cards cards-compact">
  <div class="card">
    <div class="label">Opérations</div>
    <div class="value"><?= (int) $banqueStats['nb'] ?></div>
  </div>
  <div class="card">
    <div class="label">Débits</div>
    <div class="value"><?= euro((float) $banqueStats['debits']) ?></div>
  </div>
  <div class="card">
    <div class="label">Crédits</div>
    <div class="value"><?= euro((float) $banqueStats['credits']) ?></div>
  </div>
  <div class="card">
    <div class="label">Ventes (TRI)</div>
    <div class="value"><?= euro((float) $banqueStats['ventes']) ?></div>
  </div>
</div>

<div class="banque-toolbar">
  <a class="btn" href="<?= e(page_url('import')) ?>">Importer un relevé CIC</a>
  <?php if ($operations): ?>
  <label class="banque-mois-filter">
    <span class="muted">Mois</span>
    <select id="banque-mois-filter" aria-label="Filtrer par mois (relevé)">
      <option value="">Tous</option>
      <?php foreach ($moisList as $am): ?>
        <option value="<?= e($am) ?>"><?= e($am) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="banque-tri-filter" id="banque-tri-filter">
    <button type="button" class="btn secondary" id="banque-tri-filter-btn" aria-expanded="false" aria-controls="banque-tri-filter-panel">
      Filtrer TRI <span class="banque-tri-filter-count" data-tri-filter-label>TOUT</span>
    </button>
    <div class="banque-tri-filter-panel" id="banque-tri-filter-panel" hidden>
      <label class="banque-tri-filter-item banque-tri-filter-all">
        <input type="checkbox" data-tri-filter="__all__" checked>
        <strong>TOUT</strong>
      </label>
      <label class="banque-tri-filter-item">
        <input type="checkbox" data-tri-filter="" checked>
        <span>—</span>
      </label>
      <?php foreach ($categories as $c): ?>
      <label class="banque-tri-filter-item">
        <input type="checkbox" data-tri-filter="<?= e($c['code']) ?>" checked>
        <span><?= e($c['code']) ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (!$operations): ?>
  <div class="empty">Aucune opération. Importez un relevé CIC (.xlsx ou .pdf).</div>
<?php else: ?>
<table class="data banque-grid" id="banque-grid"
       data-save-url="<?= e($saveUrl) ?>"
       data-csrf="<?= e($csrf) ?>">
  <thead>
    <tr>
      <th>Date</th>
      <th>Valeur</th>
      <th>Libellé</th>
      <th class="num">Débit</th>
      <th class="num">Crédit</th>
      <th class="banque-col-sens" title="Basculer débit ↔ crédit"></th>
      <th>TRI</th>
      <th>Mois</th>
    </tr>
  </thead>
  <tbody>
  <?php
  $fmtAmt = static function (?float $n): string {
      if ($n === null || abs($n) < 0.005) {
          return '';
      }
      return number_format(abs($n), 2, ',', ' ');
  };
  foreach ($operations as $o):
      $tri = (string) ($o['categorie_code'] ?? '');
      $mois = (string) ($o['annee_mois'] ?? '');
      $debitVal = $o['debit'] !== null ? (float) $o['debit'] : null;
      $creditVal = $o['credit'] !== null ? (float) $o['credit'] : null;
  ?>
    <tr data-operation-id="<?= (int) $o['id'] ?>" data-tri="<?= e($tri) ?>"
        data-mois="<?= e($mois) ?>"
        data-debit="<?= $debitVal !== null ? e((string) $debitVal) : '0' ?>"
        data-credit="<?= $creditVal !== null ? e((string) $creditVal) : '0' ?>"
        class="<?= $tri === '' ? 'row-tri-vide' : '' ?>">
      <td><?= date_fr($o['date_operation']) ?></td>
      <td><?= date_fr($o['date_valeur'] ?? null) ?></td>
      <td>
        <input class="cell-input banque-libelle" data-field="libelle"
               value="<?= e((string) $o['libelle']) ?>"
               aria-label="Libellé">
      </td>
      <td class="num">
        <input class="cell-input num banque-amt" data-field="debit"
               value="<?= e($fmtAmt($debitVal)) ?>"
               inputmode="decimal" aria-label="Débit">
      </td>
      <td class="num">
        <input class="cell-input num banque-amt" data-field="credit"
               value="<?= e($fmtAmt($creditVal)) ?>"
               inputmode="decimal" aria-label="Crédit">
      </td>
      <td class="banque-col-sens">
        <button type="button" class="banque-sens-btn" data-action="toggle-sens"
                title="Basculer débit ↔ crédit" aria-label="Basculer débit crédit">⇄</button>
      </td>
      <td>
        <select class="cell-tri-banque" data-field="categorie_code" aria-label="TRI">
          <option value="">—</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e($c['code']) ?>" <?= $tri === $c['code'] ? 'selected' : '' ?>>
              <?= e($c['code']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="muted"><?= e($mois) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="banque-sous-total">
      <td colspan="3">
        Sous-total relevé
        <span class="muted" id="banque-sous-total-count"></span>
        <div class="muted banque-sous-total-hint" id="banque-sous-total-hint">
          = totaux « mouvements » du relevé CIC (filtre Mois = un relevé)
        </div>
      </td>
      <td class="num" id="banque-sous-total-debit"><?= euro(abs((float) $banqueStats['debits'])) ?></td>
      <td class="num" id="banque-sous-total-credit"><?= euro((float) $banqueStats['credits']) ?></td>
      <td colspan="3"></td>
    </tr>
  </tfoot>
</table>
<p class="muted" id="banque-status" aria-live="polite"></p>
<script>
(function () {
  var grid = document.getElementById('banque-grid');
  if (!grid) return;
  var saveUrl = grid.getAttribute('data-save-url') || '';
  var csrf = grid.getAttribute('data-csrf') || '';
  var elDebit = document.getElementById('banque-sous-total-debit');
  var elCredit = document.getElementById('banque-sous-total-credit');
  var elCount = document.getElementById('banque-sous-total-count');
  var elHint = document.getElementById('banque-sous-total-hint');
  var moisSelect = document.getElementById('banque-mois-filter');
  var statusEl = document.getElementById('banque-status');

  var filterRoot = document.getElementById('banque-tri-filter');
  var filterBtn = document.getElementById('banque-tri-filter-btn');
  var filterPanel = document.getElementById('banque-tri-filter-panel');
  var filterLabel = filterRoot ? filterRoot.querySelector('[data-tri-filter-label]') : null;
  var allCb = filterRoot ? filterRoot.querySelector('[data-tri-filter="__all__"]') : null;

  function euroFr(n) {
    var neg = n < 0;
    var abs = Math.abs(n);
    var parts = abs.toFixed(2).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
    return (neg ? '-' : '') + parts[0] + ',' + parts[1] + '\u00a0€';
  }

  function parseFr(v) {
    if (v == null || v === '') return null;
    var n = parseFloat(String(v).replace(/[\s\u00a0\u202f€]/g, '').replace('−', '-').replace(',', '.'));
    if (!Number.isFinite(n)) return null;
    return Math.round(n * 100) / 100;
  }

  function formatAmtInput(n) {
    if (n == null || !Number.isFinite(n) || Math.abs(n) < 0.005) return '';
    var abs = Math.abs(n);
    var fixed = (Math.round(abs * 100) / 100).toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return parts[0] + ',' + parts[1];
  }

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg || '';
  }

  function itemCbs() {
    if (!filterRoot) return [];
    return Array.prototype.slice.call(filterRoot.querySelectorAll('input[data-tri-filter]:not([data-tri-filter="__all__"])'));
  }

  function selectedSet() {
    var set = {};
    itemCbs().forEach(function (cb) {
      if (cb.checked) set[cb.getAttribute('data-tri-filter') || ''] = true;
    });
    return set;
  }

  function updateLabel() {
    if (!filterLabel || !allCb) return;
    if (allCb.checked) {
      filterLabel.textContent = 'TOUT';
      return;
    }
    var n = itemCbs().filter(function (cb) { return cb.checked; }).length;
    filterLabel.textContent = n === 0 ? 'aucun' : (n + ' TRI');
  }

  function updateSousTotal() {
    var sumD = 0;
    var sumC = 0;
    var n = 0;
    grid.querySelectorAll('tbody tr[data-tri]').forEach(function (tr) {
      if (tr.hidden) return;
      n += 1;
      sumD += parseFloat(tr.getAttribute('data-debit') || '0') || 0;
      sumC += parseFloat(tr.getAttribute('data-credit') || '0') || 0;
    });
    if (elDebit) elDebit.textContent = euroFr(Math.abs(sumD));
    if (elCredit) elCredit.textContent = euroFr(Math.abs(sumC));
    if (elCount) elCount.textContent = n ? ' (' + n + ' ligne' + (n > 1 ? 's' : '') + ')' : '';
    if (elHint && moisSelect) {
      var m = moisSelect.value || '';
      elHint.textContent = m
        ? ('à comparer au total des mouvements du relevé ' + m)
        : 'filtre Mois = un relevé pour recouper les totaux CIC';
    }
  }

  function applyFilter() {
    var showAll = allCb && allCb.checked;
    var set = selectedSet();
    var mois = moisSelect ? (moisSelect.value || '') : '';
    grid.querySelectorAll('tbody tr[data-tri]').forEach(function (tr) {
      var tri = tr.getAttribute('data-tri') || '';
      var rowMois = tr.getAttribute('data-mois') || '';
      var okTri = showAll || !!set[tri];
      var okMois = !mois || rowMois === mois;
      tr.hidden = !(okTri && okMois);
    });
    updateLabel();
    updateSousTotal();
  }

  function syncAllFromItems() {
    if (!allCb) return;
    var items = itemCbs();
    allCb.checked = items.length > 0 && items.every(function (cb) { return cb.checked; });
  }

  function saveLigne(tr, side) {
    var oid = tr.getAttribute('data-operation-id');
    if (!oid) return;
    var libEl = tr.querySelector('[data-field="libelle"]');
    var dEl = tr.querySelector('[data-field="debit"]');
    var cEl = tr.querySelector('[data-field="credit"]');
    var libelle = libEl ? String(libEl.value || '').trim() : '';
    var debitRaw = dEl ? String(dEl.value || '').trim() : '';
    var creditRaw = cEl ? String(cEl.value || '').trim() : '';

    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_ligne');
    fd.append('operation_id', oid);
    fd.append('libelle', libelle);
    fd.append('debit', debitRaw);
    fd.append('credit', creditRaw);
    if (side) fd.append('side', side);

    setStatus('Enregistrement…');
    tr.classList.add('banque-row-saving');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        tr.classList.remove('banque-row-saving');
        if (!data || !data.ok) {
          setStatus((data && data.erreur) || 'Erreur');
          return;
        }
        if (libEl) libEl.value = data.libelle || '';
        if (dEl) dEl.value = formatAmtInput(data.debit);
        if (cEl) cEl.value = formatAmtInput(data.credit);
        tr.setAttribute('data-debit', data.debit != null ? String(data.debit) : '0');
        tr.setAttribute('data-credit', data.credit != null ? String(data.credit) : '0');
        updateSousTotal();
        setStatus('Enregistré');
        setTimeout(function () { setStatus(''); }, 1200);
      })
      .catch(function () {
        tr.classList.remove('banque-row-saving');
        setStatus('Erreur réseau');
      });
  }

  function toggleSens(tr) {
    var dEl = tr.querySelector('[data-field="debit"]');
    var cEl = tr.querySelector('[data-field="credit"]');
    if (!dEl || !cEl) return;
    var d = parseFr(dEl.value);
    var c = parseFr(cEl.value);
    if (d != null && Math.abs(d) >= 0.005) {
      cEl.value = formatAmtInput(d);
      dEl.value = '';
      saveLigne(tr, 'credit');
      return;
    }
    if (c != null && Math.abs(c) >= 0.005) {
      dEl.value = formatAmtInput(c);
      cEl.value = '';
      saveLigne(tr, 'debit');
      return;
    }
    setStatus('Rien à basculer');
    setTimeout(function () { setStatus(''); }, 1200);
  }

  if (moisSelect) {
    moisSelect.addEventListener('change', applyFilter);
  }

  if (filterBtn && filterPanel) {
    filterBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var open = filterPanel.hasAttribute('hidden');
      if (open) {
        filterPanel.removeAttribute('hidden');
        filterBtn.setAttribute('aria-expanded', 'true');
      } else {
        filterPanel.setAttribute('hidden', '');
        filterBtn.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('click', function (ev) {
      if (!filterRoot.contains(ev.target)) {
        filterPanel.setAttribute('hidden', '');
        filterBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if (filterRoot) {
    filterRoot.addEventListener('change', function (ev) {
      var t = ev.target;
      if (!(t instanceof HTMLInputElement) || !t.hasAttribute('data-tri-filter')) return;
      if (t.getAttribute('data-tri-filter') === '__all__') {
        itemCbs().forEach(function (cb) { cb.checked = t.checked; });
      } else {
        syncAllFromItems();
      }
      applyFilter();
    });
  }

  grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLSelectElement) || !t.matches('[data-field="categorie_code"]')) return;
    var tr = t.closest('tr[data-operation-id]');
    if (!tr) return;
    tr.setAttribute('data-tri', t.value || '');
    tr.classList.toggle('row-tri-vide', !t.value);
    applyFilter();
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_tri');
    fd.append('operation_id', tr.getAttribute('data-operation-id') || '');
    fd.append('categorie_code', t.value);
    t.classList.add('tri-saving');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        t.classList.remove('tri-saving');
        t.classList.toggle('tri-ok', !!(data && data.ok));
      })
      .catch(function () {
        t.classList.remove('tri-saving');
      });
  });

  grid.addEventListener('blur', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.matches('[data-field="libelle"], [data-field="debit"], [data-field="credit"]')) return;
    var tr = t.closest('tr[data-operation-id]');
    if (!tr) return;
    var field = t.getAttribute('data-field') || '';
    // Si on saisit dans une colonne, vider l’autre pour éviter débit+crédit
    if (field === 'debit' && t.value.trim() !== '') {
      var cEl = tr.querySelector('[data-field="credit"]');
      if (cEl) cEl.value = '';
      saveLigne(tr, 'debit');
      return;
    }
    if (field === 'credit' && t.value.trim() !== '') {
      var dEl = tr.querySelector('[data-field="debit"]');
      if (dEl) dEl.value = '';
      saveLigne(tr, 'credit');
      return;
    }
    saveLigne(tr, field === 'credit' ? 'credit' : 'debit');
  }, true);

  grid.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.matches('[data-field="libelle"], [data-field="debit"], [data-field="credit"]')) return;
    if (ev.key === 'Enter') {
      ev.preventDefault();
      t.blur();
    }
  });

  grid.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-action="toggle-sens"]');
    if (!btn) return;
    var tr = btn.closest('tr[data-operation-id]');
    if (tr) toggleSens(tr);
  });

  updateSousTotal();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Banque';
$page = 'banque';
require dirname(__DIR__) . '/templates/layout.php';
