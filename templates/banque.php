<?php
declare(strict_types=1);
/** @var list<array> $operations */
/** @var array|null $exercice */
/** @var array $banqueStats */
/** @var list<array{code:string,libelle:string}> $categories */
/** @var array<string, array{date:string, solde:?float}> $releveSoldes */
ob_start();

$operations = $operations ?? [];
$banqueStats = $banqueStats ?? [
    'nb' => 0,
    'debits' => 0.0,
    'credits' => 0.0,
    'ventes' => 0.0,
];
$categories = $categories ?? [];
$releveSoldes = $releveSoldes ?? [];
$csrf = csrf_token();
$saveUrl = page_url('banque');

/** @var array<string, list<array>> $opsParMois */
$opsParMois = [];
foreach ($operations as $o) {
    $am = (string) ($o['annee_mois'] ?? '');
    if ($am === '' && !empty($o['date_operation'])) {
        $am = annee_mois_from_date((string) $o['date_operation']);
    }
    if ($am === '') {
        $am = '000000';
    }
    $opsParMois[$am][] = $o;
}
// Mois récents en premier ; dans chaque mois : du plus récent au plus ancien
$moisList = array_keys($opsParMois);
rsort($moisList, SORT_STRING);
foreach ($opsParMois as &$opsMoisSort) {
    usort($opsMoisSort, static function (array $a, array $b): int {
        $da = (string) ($a['date_operation'] ?? '');
        $db = (string) ($b['date_operation'] ?? '');
        if ($da !== $db) {
            return $db <=> $da;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
}
unset($opsMoisSort);

$fmtAmt = static function (?float $n): string {
    if ($n === null || abs($n) < 0.005) {
        return '';
    }
    return number_format(abs($n), 2, ',', ' ');
};

$dateFinMois = static function (string|int $ym, array $ops): string {
    $ym = (string) $ym;
    $last = '';
    foreach ($ops as $o) {
        $d = (string) ($o['date_operation'] ?? '');
        if ($d > $last) {
            $last = $d;
        }
    }
    if ($last !== '') {
        return $last;
    }
    if (preg_match('/^(\d{4})(\d{2})$/', $ym, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $day = (int) date('t', mktime(0, 0, 0, $mo, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $mo, $day);
    }
    return '';
};
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
        <?php if ((string) $am === '000000') {
            continue;
        } ?>
        <option value="<?= e((string) $am) ?>"><?= e((string) $am) ?></option>
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
       data-csrf="<?= e($csrf) ?>"
       data-releve-soldes="<?= e(json_encode($releveSoldes, JSON_UNESCAPED_UNICODE) ?: '{}') ?>">
  <thead>
    <tr>
      <th class="banque-col-date">Date</th>
      <th class="banque-col-valeur">Valeur</th>
      <th class="banque-col-libelle">Libellé</th>
      <th class="num banque-col-amt">Débit</th>
      <th class="num banque-col-amt">Crédit</th>
      <th class="banque-col-tri">TRI</th>
      <th class="banque-col-mois">Mois</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($moisList as $moisKeyRaw):
      // PHP caste les clés "202607" en int → toujours forcer string
      $moisKey = (string) $moisKeyRaw;
      $opsMois = $opsParMois[$moisKeyRaw] ?? $opsParMois[$moisKey] ?? [];
      $sumD = 0.0;
      $sumC = 0.0;
      foreach ($opsMois as $o) {
          if ($o['debit'] !== null) {
              $sumD += abs((float) $o['debit']);
          }
          if ($o['credit'] !== null) {
              $sumC += (float) $o['credit'];
          }
      }
      $meta = $releveSoldes[$moisKey] ?? $releveSoldes[$moisKeyRaw] ?? null;
      $dateSolde = is_array($meta) && !empty($meta['date'])
          ? (string) $meta['date']
          : $dateFinMois($moisKey, $opsMois);
      $soldeVal = is_array($meta) && array_key_exists('solde', $meta) ? $meta['solde'] : null;
  ?>
    <?php foreach ($opsMois as $o):
        $tri = (string) ($o['categorie_code'] ?? '');
        $mois = (string) ($o['annee_mois'] ?? $moisKey);
        $debitVal = $o['debit'] !== null ? (float) $o['debit'] : null;
        $creditVal = $o['credit'] !== null ? (float) $o['credit'] : null;
    ?>
    <tr data-operation-id="<?= (int) $o['id'] ?>" data-tri="<?= e($tri) ?>"
        data-mois="<?= e($mois) ?>"
        data-debit="<?= $debitVal !== null ? e((string) $debitVal) : '0' ?>"
        data-credit="<?= $creditVal !== null ? e((string) $creditVal) : '0' ?>"
        class="<?= $tri === '' ? 'row-tri-vide' : '' ?>">
      <td class="banque-col-date"><?= date_fr($o['date_operation']) ?></td>
      <td class="banque-col-valeur"><?= date_fr($o['date_valeur'] ?? null) ?></td>
      <td class="banque-col-libelle">
        <input class="cell-input banque-libelle" data-field="libelle"
               value="<?= e((string) $o['libelle']) ?>"
               aria-label="Libellé">
      </td>
      <td class="num banque-col-amt">
        <input class="cell-input num banque-amt" data-field="debit"
               value="<?= e($fmtAmt($debitVal)) ?>"
               inputmode="decimal" aria-label="Débit">
      </td>
      <td class="num banque-col-amt">
        <input class="cell-input num banque-amt" data-field="credit"
               value="<?= e($fmtAmt($creditVal)) ?>"
               inputmode="decimal" aria-label="Crédit">
      </td>
      <td class="banque-col-tri">
        <select class="cell-tri-banque" data-field="categorie_code" aria-label="TRI">
          <option value="">—</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e($c['code']) ?>" <?= $tri === $c['code'] ? 'selected' : '' ?>>
              <?= e($c['code']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="muted banque-col-mois"><?= e($mois) ?></td>
    </tr>
    <?php endforeach; ?>

    <tr class="banque-total-mvt" data-mois="<?= e($moisKey) ?>" data-summary="1">
      <td colspan="3"><strong>Total des mouvements</strong></td>
      <td class="num banque-col-amt" data-mvt-debit><?= euro($sumD) ?></td>
      <td class="num banque-col-amt" data-mvt-credit><?= euro($sumC) ?></td>
      <td colspan="2"></td>
    </tr>
    <tr class="banque-solde-crediteur" data-mois="<?= e($moisKey) ?>" data-summary="1"
        data-solde="<?= $soldeVal !== null ? e((string) $soldeVal) : '' ?>"
        data-date-solde="<?= e($dateSolde) ?>">
      <td colspan="3">
        <strong>SOLDE CRÉDITEUR<?= $dateSolde !== '' ? ' AU ' . date_fr($dateSolde) : '' ?></strong>
      </td>
      <td class="num banque-col-amt"></td>
      <td class="num banque-col-amt" data-solde-montant>
        <?= $soldeVal !== null ? euro((float) $soldeVal) : '—' ?>
      </td>
      <td colspan="2"></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="muted" id="banque-status" aria-live="polite"></p>
<script>
(function () {
  var grid = document.getElementById('banque-grid');
  if (!grid) return;
  var saveUrl = grid.getAttribute('data-save-url') || '';
  var csrf = grid.getAttribute('data-csrf') || '';
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

  function updateMoisSummaries() {
    var moisKeys = {};
    grid.querySelectorAll('tr.banque-total-mvt[data-mois]').forEach(function (tr) {
      moisKeys[tr.getAttribute('data-mois') || ''] = true;
    });
    Object.keys(moisKeys).forEach(function (mois) {
      var sumD = 0;
      var sumC = 0;
      var visibleOps = 0;
      grid.querySelectorAll('tbody tr[data-operation-id][data-mois="' + mois + '"]').forEach(function (tr) {
        if (tr.hidden) return;
        visibleOps += 1;
        sumD += Math.abs(parseFloat(tr.getAttribute('data-debit') || '0') || 0);
        sumC += Math.abs(parseFloat(tr.getAttribute('data-credit') || '0') || 0);
      });
      var hideSummary = visibleOps === 0;
      grid.querySelectorAll('tbody tr[data-summary][data-mois="' + mois + '"]').forEach(function (tr) {
        tr.hidden = hideSummary;
      });
      var mvt = grid.querySelector('tr.banque-total-mvt[data-mois="' + mois + '"]');
      if (mvt && !hideSummary) {
        var dEl = mvt.querySelector('[data-mvt-debit]');
        var cEl = mvt.querySelector('[data-mvt-credit]');
        if (dEl) dEl.textContent = euroFr(sumD);
        if (cEl) cEl.textContent = euroFr(sumC);
      }
    });
  }

  function applyFilter() {
    var showAll = allCb && allCb.checked;
    var set = selectedSet();
    var mois = moisSelect ? (moisSelect.value || '') : '';
    grid.querySelectorAll('tbody tr[data-operation-id]').forEach(function (tr) {
      var tri = tr.getAttribute('data-tri') || '';
      var rowMois = tr.getAttribute('data-mois') || '';
      var okTri = showAll || !!set[tri];
      var okMois = !mois || rowMois === mois;
      tr.hidden = !(okTri && okMois);
    });
    updateLabel();
    updateMoisSummaries();
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
        updateMoisSummaries();
        setStatus('Enregistré');
        setTimeout(function () { setStatus(''); }, 1200);
      })
      .catch(function () {
        tr.classList.remove('banque-row-saving');
        setStatus('Erreur réseau');
      });
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

  updateMoisSummaries();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Banque';
$page = 'banque';
require dirname(__DIR__) . '/templates/layout.php';
