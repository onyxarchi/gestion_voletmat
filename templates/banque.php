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
$moisOuvert = '';
foreach ($moisList as $am) {
    if ((string) $am !== '000000') {
        $moisOuvert = (string) $am;
        break;
    }
}
$dateSaisieDefaut = '';
$dateSaisieMin = '';
$dateSaisieMax = '';
if ($moisOuvert !== '' && preg_match('/^(\d{4})(\d{2})$/', $moisOuvert, $mm)) {
    $y = (int) $mm[1];
    $m = (int) $mm[2];
    $dateSaisieMin = sprintf('%04d-%02d-01', $y, $m);
    $dateSaisieMax = date('Y-m-t', mktime(0, 0, 0, $m, 1, $y) ?: time());
    $today = date('Y-m-d');
    if (annee_mois_from_date($today) === $moisOuvert) {
        $dateSaisieDefaut = $today;
    } else {
        $dateSaisieDefaut = $dateSaisieMax;
    }
}
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

/** Doublons probables : même date + même montant (débit ou crédit) + libellé proche */
$doublonIds = [];
$byKey = [];
foreach ($operations as $o) {
    $lib = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) ($o['libelle'] ?? ''))) ?? '', 'UTF-8');
    $lib = mb_substr($lib, 0, 48, 'UTF-8');
    $amt = $o['debit'] !== null ? abs((float) $o['debit']) : abs((float) ($o['credit'] ?? 0));
    $key = ((string) ($o['date_operation'] ?? '')) . '|' . number_format($amt, 2, '.', '') . '|' . $lib;
    $byKey[$key][] = (int) $o['id'];
}
foreach ($byKey as $ids) {
    if (count($ids) < 2) {
        continue;
    }
    foreach ($ids as $id) {
        $doublonIds[$id] = true;
    }
}

$fmtAmt = static function (?float $n): string {
    if ($n === null || abs($n) < 0.005) {
        return '';
    }
    return number_format(abs($n), 2, ',', ' ') . ' €';
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

// Solde d’ouverture exercice → affiché tout en bas du tableau (lecture du plus récent au plus ancien)
$soldeOuverture = null;
$soldeOuvertureDate = '';
$soldeOuvertureYm = '';
if (is_array($exercice ?? null)) {
    if (isset($exercice['solde_ouverture']) && $exercice['solde_ouverture'] !== null && $exercice['solde_ouverture'] !== '') {
        $soldeOuverture = (float) $exercice['solde_ouverture'];
    }
    $soldeOuvertureDate = trim((string) ($exercice['solde_ouverture_date'] ?? ''));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $soldeOuvertureDate, $om)) {
        $soldeOuvertureYm = $om[1] . $om[2];
    }
}
?>
<h1>Banque</h1>

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
        <option value="<?= e((string) $am) ?>"><?= e((string) $am) ?><?= (string) $am === $moisOuvert ? ' · en cours' : '' ?></option>
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
  <?php if ($moisOuvert !== ''): ?>
  <p class="muted banque-mois-lock-hint">
    Suppression uniquement sur <?= e($moisOuvert) ?> (mois en cours). Les mois précédents sont considérés validés.
  </p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!$operations): ?>
  <div class="empty">Aucune opération. Importez un relevé CIC (.xlsx ou .pdf).</div>
<?php else: ?>
<table class="data banque-grid" id="banque-grid"
       data-save-url="<?= e($saveUrl) ?>"
       data-csrf="<?= e($csrf) ?>"
       data-mois-ouvert="<?= e($moisOuvert) ?>"
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
      <th class="banque-col-del" aria-label="Supprimer"></th>
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
      // Solde d’ouverture (ex. 30/06/2025) : pas en tête du mois, tout en bas du tableau
      $estOuverture = $soldeOuvertureYm !== '' && $moisKey === $soldeOuvertureYm;
  ?>
    <?php if (!$estOuverture): ?>
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
      <td colspan="3"></td>
    </tr>
    <?php endif; ?>
    <tr class="banque-total-mvt" data-mois="<?= e($moisKey) ?>" data-summary="1">
      <td colspan="3">
        <strong>Total des mouvements</strong>
        <span class="muted banque-mvt-net" data-mvt-net-label>
          · net D−C&nbsp;: <span data-mvt-net><?= euro($sumD - $sumC) ?></span>
        </span>
      </td>
      <td class="num banque-col-amt" data-mvt-debit><?= euro($sumD) ?></td>
      <td class="num banque-col-amt" data-mvt-credit><?= euro($sumC) ?></td>
      <td colspan="3"></td>
    </tr>
    <?php if ($moisKey === $moisOuvert): ?>
    <tr class="banque-new-row" data-mois="<?= e($moisKey) ?>" data-new="1">
      <td class="banque-col-date">
        <input type="date" class="cell-input banque-date" data-field="date_operation"
               value="<?= e($dateSaisieDefaut) ?>"
               min="<?= e($dateSaisieMin) ?>" max="<?= e($dateSaisieMax) ?>"
               aria-label="Date opération" required>
      </td>
      <td class="banque-col-valeur">
        <input type="date" class="cell-input banque-date" data-field="date_valeur"
               value="<?= e($dateSaisieDefaut) ?>"
               min="<?= e($dateSaisieMin) ?>" max="<?= e($dateSaisieMax) ?>"
               aria-label="Date valeur">
      </td>
      <td class="banque-col-libelle">
        <input class="cell-input banque-libelle" data-field="libelle"
               value="" placeholder="Libellé…" aria-label="Libellé">
      </td>
      <td class="num banque-col-amt">
        <input class="cell-input num banque-amt" data-field="debit"
               value="" inputmode="decimal" placeholder="0,00" aria-label="Débit">
      </td>
      <td class="num banque-col-amt">
        <input class="cell-input num banque-amt" data-field="credit"
               value="" inputmode="decimal" placeholder="0,00" aria-label="Crédit">
      </td>
      <td class="banque-col-tri">
        <select class="cell-tri-banque" data-field="categorie_code" aria-label="TRI">
          <option value="">—</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e($c['code']) ?>"><?= e($c['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="muted banque-col-mois"><?= e($moisKey) ?></td>
      <td class="banque-col-del">
        <button type="button" class="btn-add-ligne banque-add"
                title="Ajouter la ligne" aria-label="Ajouter">+</button>
      </td>
    </tr>
    <?php endif; ?>
    <?php foreach ($opsMois as $o):
        $tri = (string) ($o['categorie_code'] ?? '');
        $mois = (string) ($o['annee_mois'] ?? $moisKey);
        $debitVal = $o['debit'] !== null ? (float) $o['debit'] : null;
        $creditVal = $o['credit'] !== null ? (float) $o['credit'] : null;
        $oid = (int) $o['id'];
        $isDup = isset($doublonIds[$oid]);
        $supprOk = $moisOuvert === '' || $mois === '' || $mois >= $moisOuvert;
    ?>
    <tr data-operation-id="<?= $oid ?>" data-tri="<?= e($tri) ?>"
        data-mois="<?= e($mois) ?>"
        data-debit="<?= $debitVal !== null ? e((string) abs($debitVal)) : '0' ?>"
        data-credit="<?= $creditVal !== null ? e((string) abs($creditVal)) : '0' ?>"
        class="<?= $tri === '' ? 'row-tri-vide' : '' ?><?= $isDup ? ' banque-doublon' : '' ?><?= $supprOk ? '' : ' banque-mois-clos' ?>">
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
      <td class="banque-col-del">
        <?php if ($supprOk): ?>
        <button type="button" class="btn-del-ligne banque-del"
                title="<?= $isDup ? 'Doublon probable — supprimer' : 'Supprimer cette ligne' ?>"
                aria-label="Supprimer">×</button>
        <?php else: ?>
        <span class="banque-del-locked" title="Mois validé — suppression impossible" aria-label="Mois validé">·</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <?php if ($soldeOuverture !== null && $soldeOuvertureDate !== ''): ?>
    <tr class="banque-solde-crediteur banque-solde-ouverture" data-mois="<?= e($soldeOuvertureYm) ?>"
        data-summary="1" data-ouverture="1"
        data-solde="<?= e((string) $soldeOuverture) ?>"
        data-date-solde="<?= e($soldeOuvertureDate) ?>">
      <td colspan="3">
        <strong>SOLDE CRÉDITEUR AU <?= date_fr($soldeOuvertureDate) ?></strong>
      </td>
      <td class="num banque-col-amt"></td>
      <td class="num banque-col-amt" data-solde-montant><?= euro($soldeOuverture) ?></td>
      <td colspan="3"></td>
    </tr>
  <?php endif; ?>
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
  var moisOuvert = grid.getAttribute('data-mois-ouvert') || '';

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
    return parts[0] + ',' + parts[1] + ' €';
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

  function rowAmt(tr, which) {
    return Math.abs(parseFloat(tr.getAttribute(which === 'debit' ? 'data-debit' : 'data-credit') || '0') || 0);
  }

  function syncRowAmtsFromInputs(tr) {
    var dEl = tr.querySelector('[data-field="debit"]');
    var cEl = tr.querySelector('[data-field="credit"]');
    var d = dEl ? parseFr(dEl.value) : null;
    var c = cEl ? parseFr(cEl.value) : null;
    tr.setAttribute('data-debit', d != null && Math.abs(d) >= 0.005 ? String(Math.abs(d)) : '0');
    tr.setAttribute('data-credit', c != null && Math.abs(c) >= 0.005 ? String(Math.abs(c)) : '0');
  }

  function updateSoldes() {
    var ouvTr = grid.querySelector('tr[data-ouverture="1"]');
    if (!ouvTr) return;
    var cursor = parseFloat(ouvTr.getAttribute('data-solde') || '');
    if (!Number.isFinite(cursor)) return;
    var ouvYm = ouvTr.getAttribute('data-mois') || '';
    var moisList = [];
    grid.querySelectorAll('tr.banque-total-mvt[data-mois]').forEach(function (tr) {
      var m = tr.getAttribute('data-mois') || '';
      if (m && moisList.indexOf(m) === -1) moisList.push(m);
    });
    moisList.sort();
    moisList.forEach(function (mois) {
      if (mois < ouvYm) return;
      if (mois === ouvYm) {
        return; // point de référence déjà dans data-solde ouverture
      }
      var sumD = 0;
      var sumC = 0;
      grid.querySelectorAll('tbody tr[data-operation-id][data-mois="' + mois + '"]').forEach(function (tr) {
        sumD += rowAmt(tr, 'debit');
        sumC += rowAmt(tr, 'credit');
      });
      cursor = Math.round((cursor + sumC - sumD) * 100) / 100;
      var soldeTr = grid.querySelector(
        'tr.banque-solde-crediteur[data-mois="' + mois + '"]:not([data-ouverture])'
      );
      if (soldeTr) {
        soldeTr.setAttribute('data-solde', String(cursor));
        var el = soldeTr.querySelector('[data-solde-montant]');
        if (el) el.textContent = euroFr(cursor);
      }
    });
  }

  function updateMoisSummaries() {
    var moisKeys = {};
    grid.querySelectorAll('tr.banque-total-mvt[data-mois]').forEach(function (tr) {
      moisKeys[tr.getAttribute('data-mois') || ''] = true;
    });
    var moisFilter = moisSelect ? (moisSelect.value || '') : '';
    Object.keys(moisKeys).forEach(function (mois) {
      var sumD = 0;
      var sumC = 0;
      var visibleOps = 0;
      grid.querySelectorAll('tbody tr[data-operation-id][data-mois="' + mois + '"]').forEach(function (tr) {
        if (tr.hidden) return;
        visibleOps += 1;
        sumD += rowAmt(tr, 'debit');
        sumC += rowAmt(tr, 'credit');
      });
      var hideSummary = visibleOps === 0;
      grid.querySelectorAll('tbody tr[data-summary][data-mois="' + mois + '"]').forEach(function (tr) {
        if (tr.getAttribute('data-ouverture') === '1') {
          return;
        }
        tr.hidden = hideSummary;
      });
      var mvt = grid.querySelector('tr.banque-total-mvt[data-mois="' + mois + '"]');
      if (mvt && !hideSummary) {
        var dEl = mvt.querySelector('[data-mvt-debit]');
        var cEl = mvt.querySelector('[data-mvt-credit]');
        var nEl = mvt.querySelector('[data-mvt-net]');
        if (dEl) dEl.textContent = euroFr(sumD);
        if (cEl) cEl.textContent = euroFr(sumC);
        if (nEl) nEl.textContent = euroFr(Math.round((sumD - sumC) * 100) / 100);
      }
    });
    grid.querySelectorAll('tr[data-ouverture="1"]').forEach(function (tr) {
      var om = tr.getAttribute('data-mois') || '';
      tr.hidden = moisFilter !== '' && moisFilter !== om;
    });
    updateSoldes();
  }

  function recalcAfterEdit(tr) {
    if (tr) syncRowAmtsFromInputs(tr);
    updateMoisSummaries();
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
    grid.querySelectorAll('tbody tr.banque-new-row').forEach(function (tr) {
      var rowMois = tr.getAttribute('data-mois') || '';
      tr.hidden = !!(mois && rowMois !== mois);
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
        tr.setAttribute(
          'data-debit',
          data.debit != null && Math.abs(Number(data.debit)) >= 0.005
            ? String(Math.abs(Number(data.debit)))
            : '0'
        );
        tr.setAttribute(
          'data-credit',
          data.credit != null && Math.abs(Number(data.credit)) >= 0.005
            ? String(Math.abs(Number(data.credit)))
            : '0'
        );
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

  grid.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLElement)) return;
    var addBtn = t.closest('.banque-add');
    if (addBtn) {
      var newTr = addBtn.closest('tr.banque-new-row');
      if (newTr) saveNouvelleLigne(newTr);
      return;
    }
    var btn = t.closest('.banque-del');
    if (!btn) return;
    var tr = btn.closest('tr[data-operation-id]');
    if (!tr) return;
    var rowMois = tr.getAttribute('data-mois') || '';
    if (moisOuvert && rowMois && rowMois < moisOuvert) {
      setStatus('Mois ' + rowMois + ' validé — suppression impossible');
      return;
    }
    var oid = tr.getAttribute('data-operation-id');
    if (!oid) return;
    var lib = '';
    var libEl = tr.querySelector('[data-field="libelle"]');
    if (libEl instanceof HTMLInputElement) lib = libEl.value;
    var msg = 'Supprimer cette ligne ?';
    if (tr.classList.contains('banque-doublon')) {
      msg = 'Doublon probable. Supprimer cette ligne ?';
    }
    if (lib) msg += '\n' + lib;
    if (!window.confirm(msg)) return;

    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'suppr_ligne');
    fd.append('operation_id', oid);
    setStatus('Suppression…');
    tr.classList.add('banque-row-saving');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          tr.classList.remove('banque-row-saving');
          setStatus((data && data.erreur) || 'Erreur');
          return;
        }
        tr.remove();
        updateMoisSummaries();
        setStatus('Ligne supprimée');
        setTimeout(function () { setStatus(''); }, 1200);
      })
      .catch(function () {
        tr.classList.remove('banque-row-saving');
        setStatus('Erreur réseau');
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
      recalcAfterEdit(tr);
      saveLigne(tr, 'debit');
      return;
    }
    if (field === 'credit' && t.value.trim() !== '') {
      var dEl = tr.querySelector('[data-field="debit"]');
      if (dEl) dEl.value = '';
      recalcAfterEdit(tr);
      saveLigne(tr, 'credit');
      return;
    }
    recalcAfterEdit(tr);
    saveLigne(tr, field === 'credit' ? 'credit' : 'debit');
  }, true);

  grid.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.matches('[data-field="debit"], [data-field="credit"]')) return;
    var tr = t.closest('tr[data-operation-id]');
    if (!tr) return;
    recalcAfterEdit(tr);
  });

  grid.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement) && !(t instanceof HTMLSelectElement)) return;
    var newTr = t.closest('tr.banque-new-row');
    if (newTr) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        saveNouvelleLigne(newTr);
      }
      return;
    }
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.matches('[data-field="libelle"], [data-field="debit"], [data-field="credit"]')) return;
    if (ev.key === 'Enter') {
      ev.preventDefault();
      t.blur();
    }
  });

  function triSelectHtml() {
    var draft = grid.querySelector('tr.banque-new-row select[data-field="categorie_code"]');
    return draft ? draft.innerHTML : '<option value="">—</option>';
  }

  function createOpRow(op) {
    var tri = op.categorie_code || '';
    var debitAbs = op.debit != null && Math.abs(Number(op.debit)) >= 0.005
      ? Math.abs(Number(op.debit)) : 0;
    var creditAbs = op.credit != null && Math.abs(Number(op.credit)) >= 0.005
      ? Math.abs(Number(op.credit)) : 0;
    var tr = document.createElement('tr');
    tr.setAttribute('data-operation-id', String(op.id));
    tr.setAttribute('data-tri', tri);
    tr.setAttribute('data-mois', op.annee_mois || '');
    tr.setAttribute('data-debit', String(debitAbs));
    tr.setAttribute('data-credit', String(creditAbs));
    if (!tri) tr.classList.add('row-tri-vide');

    tr.innerHTML =
      '<td class="banque-col-date"></td>' +
      '<td class="banque-col-valeur"></td>' +
      '<td class="banque-col-libelle">' +
        '<input class="cell-input banque-libelle" data-field="libelle" aria-label="Libellé">' +
      '</td>' +
      '<td class="num banque-col-amt">' +
        '<input class="cell-input num banque-amt" data-field="debit" inputmode="decimal" aria-label="Débit">' +
      '</td>' +
      '<td class="num banque-col-amt">' +
        '<input class="cell-input num banque-amt" data-field="credit" inputmode="decimal" aria-label="Crédit">' +
      '</td>' +
      '<td class="banque-col-tri">' +
        '<select class="cell-tri-banque" data-field="categorie_code" aria-label="TRI">' +
          triSelectHtml() +
        '</select>' +
      '</td>' +
      '<td class="muted banque-col-mois"></td>' +
      '<td class="banque-col-del">' +
        '<button type="button" class="btn-del-ligne banque-del" title="Supprimer cette ligne" aria-label="Supprimer">×</button>' +
      '</td>';

    tr.children[0].textContent = op.date_operation_fr || op.date_operation || '';
    tr.children[1].textContent = op.date_valeur_fr || op.date_valeur || '';
    var libEl = tr.querySelector('[data-field="libelle"]');
    if (libEl) libEl.value = op.libelle || '';
    var dEl = tr.querySelector('[data-field="debit"]');
    if (dEl) dEl.value = formatAmtInput(op.debit);
    var cEl = tr.querySelector('[data-field="credit"]');
    if (cEl) cEl.value = formatAmtInput(op.credit);
    var sel = tr.querySelector('[data-field="categorie_code"]');
    if (sel) sel.value = tri;
    tr.querySelector('.banque-col-mois').textContent = op.annee_mois || '';
    return tr;
  }

  function resetNouvelleLigne(tr) {
    var dateOp = tr.querySelector('[data-field="date_operation"]');
    var dateVal = tr.querySelector('[data-field="date_valeur"]');
    var lib = tr.querySelector('[data-field="libelle"]');
    var dEl = tr.querySelector('[data-field="debit"]');
    var cEl = tr.querySelector('[data-field="credit"]');
    var sel = tr.querySelector('[data-field="categorie_code"]');
    if (lib) lib.value = '';
    if (dEl) dEl.value = '';
    if (cEl) cEl.value = '';
    if (sel) sel.value = '';
    if (dateOp && dateVal && dateOp.value) dateVal.value = dateOp.value;
    if (lib) lib.focus();
  }

  function saveNouvelleLigne(tr) {
    if (!tr || tr.classList.contains('banque-row-saving')) return;
    var dateOp = tr.querySelector('[data-field="date_operation"]');
    var dateVal = tr.querySelector('[data-field="date_valeur"]');
    var libEl = tr.querySelector('[data-field="libelle"]');
    var dEl = tr.querySelector('[data-field="debit"]');
    var cEl = tr.querySelector('[data-field="credit"]');
    var sel = tr.querySelector('[data-field="categorie_code"]');
    var libelle = libEl ? String(libEl.value || '').trim() : '';
    var debitRaw = dEl ? String(dEl.value || '').trim() : '';
    var creditRaw = cEl ? String(cEl.value || '').trim() : '';
    if (!dateOp || !dateOp.value) {
      setStatus('Indiquez une date');
      if (dateOp) dateOp.focus();
      return;
    }
    if (!libelle) {
      setStatus('Libellé obligatoire');
      if (libEl) libEl.focus();
      return;
    }
    if (!debitRaw && !creditRaw) {
      setStatus('Indiquez un débit ou un crédit');
      if (dEl) dEl.focus();
      return;
    }
    var side = creditRaw && !debitRaw ? 'credit' : 'debit';
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'creer_ligne');
    fd.append('date_operation', dateOp.value);
    fd.append('date_valeur', dateVal && dateVal.value ? dateVal.value : dateOp.value);
    fd.append('libelle', libelle);
    fd.append('debit', debitRaw);
    fd.append('credit', creditRaw);
    fd.append('side', side);
    fd.append('categorie_code', sel ? sel.value : '');

    setStatus('Ajout…');
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
        if (!data || !data.ok || !data.operation) {
          setStatus((data && data.erreur) || 'Erreur');
          return;
        }
        var row = createOpRow(data.operation);
        tr.parentNode.insertBefore(row, tr.nextSibling);
        resetNouvelleLigne(tr);
        applyFilter();
        updateMoisSummaries();
        setStatus('Ligne ajoutée');
        setTimeout(function () { setStatus(''); }, 1200);
      })
      .catch(function () {
        tr.classList.remove('banque-row-saving');
        setStatus('Erreur réseau');
      });
  }

  grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.matches('tr.banque-new-row [data-field="date_operation"]')) return;
    var tr = t.closest('tr.banque-new-row');
    if (!tr) return;
    var dateVal = tr.querySelector('[data-field="date_valeur"]');
    if (dateVal && !dateVal.value) dateVal.value = t.value;
  });

  // Normaliser data-debit / data-credit en valeurs absolues (base = débits négatifs)
  grid.querySelectorAll('tbody tr[data-operation-id]').forEach(function (tr) {
    tr.setAttribute('data-debit', String(rowAmt(tr, 'debit')));
    tr.setAttribute('data-credit', String(rowAmt(tr, 'credit')));
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
