<?php
declare(strict_types=1);
/** @var array|null $exercice */
/** @var array $previsionnel */
ob_start();

$lignes = $previsionnel['lignes'] ?? [];
$totaux = $previsionnel['totaux'] ?? [
    'budget_ht' => 0.0,
    'budget_tva' => 0.0,
    'budget_ttc' => 0.0,
    'reel' => 0.0,
    'ecart' => 0.0,
];
$synthese = $previsionnel['synthese'] ?? [];
$objectif = (float) ($previsionnel['objectif_ca_ht'] ?? 0);
$margePct = (float) ($previsionnel['marge_pct'] ?? 0);
$margeNet = (float) ($previsionnel['marge_net'] ?? 0);
$nbMois = max(1, (int) ($previsionnel['nb_mois'] ?? 12));
$csrf = csrf_token();
$saveUrl = page_url('previsionnel');

$fmtInput = static function (float $n): string {
    if (abs($n) < 0.005) {
        return '';
    }
    return number_format($n, 2, ',', ' ');
};
$fmtPct = static function (float $n): string {
    if (abs($n) < 0.00005) {
        return '0';
    }
    $s = rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    return $s === '' ? '0' : $s;
};
?>
<div class="planning-top">
  <h1>Prévisionnel</h1>
  <?php if ($exercice): ?>
    <p class="muted prev-duree">
      Exercice sur <strong><?= (int) $nbMois ?> mois</strong>
      — les lignes <em>mensuelles</em> (REM, URSSAF, PREV, PER, PJ, SMABTP, COMPTA, LOGICIEL, TEL, NET, BANQUE, BUREAU)
      sont budgétées sur toute la période ; les autres restent un total unique.
    </p>
  <?php endif; ?>
</div>
<?php if (!$exercice): ?>
  <p class="lead">Aucun exercice pour la date du jour.</p>
  <div class="empty">Impossible d’afficher le prévisionnel sans exercice.</div>
<?php elseif (!$lignes): ?>
  <div class="empty">Aucun budget importé pour cet exercice.</div>
<?php else: ?>

<table class="data previsionnel-grid" id="previsionnel-grid"
       data-save-url="<?= e($saveUrl) ?>"
       data-csrf="<?= e($csrf) ?>"
       data-nb-mois="<?= (int) $nbMois ?>">
  <colgroup>
    <col class="prev-col-tri">
    <col class="prev-col-lib">
    <col class="prev-col-amt">
    <col class="prev-col-amt">
    <col class="prev-col-amt">
    <col class="prev-col-gap">
    <col class="prev-col-amt">
    <col class="prev-col-amt">
  </colgroup>
  <thead>
    <tr class="prev-head-titles">
      <th colspan="2" class="prev-title-previ">PREVISIONNEL (<?= (int) $nbMois ?> mois)</th>
      <th class="num">HT</th>
      <th class="num">TVA</th>
      <th class="num">TTC</th>
      <th class="prev-gap"></th>
      <th class="num prev-title-reel">REEL</th>
      <th class="num">ÉCART (TTC)</th>
    </tr>
    <tr class="prev-head-sub">
      <th>TRI</th>
      <th>Libellé</th>
      <th class="num" colspan="3">Budget (modifiable)</th>
      <th class="prev-gap"></th>
      <th class="num">TOTAUX (TTC)</th>
      <th class="num">Budget − réel</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($lignes as $l):
      $fam = preg_replace('/[^a-z_]/', '', (string) ($l['famille'] ?? 'neutre')) ?: 'neutre';
      $ecart = (float) $l['ecart'];
      $editable = ($l['famille'] ?? '') !== 'extra';
      $code = (string) $l['code'];
      $mensuel = !empty($l['mensuel']);
  ?>
    <tr class="prev-row prev-<?= e($fam) ?>"
        data-code="<?= e($code) ?>"
        data-mensuel="<?= $mensuel ? '1' : '0' ?>"
        data-reel="<?= e((string) round((float) $l['reel'], 2)) ?>">
      <td class="prev-code"><strong><?= e($code) ?></strong></td>
      <td>
        <?= e($l['libelle']) ?>
        <?php if ($mensuel): ?>
          <span class="prev-badge-mensuel" title="Charge mensuelle × <?= (int) $nbMois ?> mois">mensuel</span>
        <?php endif; ?>
        <?php if ($code === 'REM'):
            $netMensuel = round((float) $l['budget_ht'] / $nbMois, 2);
        ?>
          <span class="prev-net-mensuel" data-net-mensuel>
            (<?= number_format($netMensuel, 2, ',', ' ') ?> € / mois)
          </span>
        <?php endif; ?>
      </td>
      <?php if ($editable): ?>
      <td class="num">
        <input class="cell-input num prev-budget" data-field="ht"
               value="<?= e($fmtInput((float) $l['budget_ht'])) ?>"
               inputmode="decimal" aria-label="HT <?= e($code) ?>">
      </td>
      <td class="num">
        <input class="cell-input num prev-budget" data-field="tva"
               value="<?= e($fmtInput((float) $l['budget_tva'])) ?>"
               inputmode="decimal" aria-label="TVA <?= e($code) ?>">
      </td>
      <td class="num">
        <input class="cell-input num prev-budget prev-ttc" data-field="ttc"
               value="<?= e($fmtInput((float) $l['budget_ttc'])) ?>"
               inputmode="decimal" aria-label="TTC <?= e($code) ?>">
      </td>
      <?php else: ?>
      <td class="num"><?= euro((float) $l['budget_ht']) ?></td>
      <td class="num muted"><?= abs((float) $l['budget_tva']) < 0.005 ? '' : euro((float) $l['budget_tva']) ?></td>
      <td class="num"><strong><?= euro((float) $l['budget_ttc']) ?></strong></td>
      <?php endif; ?>
      <td class="prev-gap"></td>
      <td class="num"><?= euro((float) $l['reel']) ?></td>
      <td class="num prev-ecart <?= $ecart < -0.005 ? 'restant-neg' : ($ecart > 0.005 ? 'restant-zero' : '') ?>">
        <?= euro($ecart) ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="prev-totaux">
      <td colspan="2"><strong>Totaux</strong></td>
      <td class="num" data-tot="ht"><?= euro((float) $totaux['budget_ht']) ?></td>
      <td class="num" data-tot="tva"><?= euro((float) ($totaux['budget_tva'] ?? 0)) ?></td>
      <td class="num" data-tot="ttc"><?= euro((float) $totaux['budget_ttc']) ?></td>
      <td class="prev-gap"></td>
      <td class="num" data-tot="reel"><?= euro((float) $totaux['reel']) ?></td>
      <td class="num" data-tot="ecart"><?= euro((float) $totaux['ecart']) ?></td>
    </tr>
  </tfoot>
</table>
<p class="muted" id="prev-status" aria-live="polite"></p>

<?php if ($synthese): ?>
<h2 class="prev-synth-title">Synthèse</h2>
<table class="data previsionnel-synth" id="previsionnel-synth"
       data-nb-mois="<?= (int) $nbMois ?>">
  <colgroup>
    <col class="prev-col-synth-label">
    <col class="prev-col-amt">
    <col class="prev-col-amt">
    <col class="prev-col-gap">
    <col class="prev-col-synth-reel">
  </colgroup>
  <thead>
    <tr>
      <th></th>
      <th class="num">PRÉVISIONNEL HT</th>
      <th class="num">par mois</th>
      <th class="prev-gap"></th>
      <th class="num">RÉEL (TTC)</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($synthese as $i => $s):
      $fam = preg_replace('/[^a-z_]/', '', (string) ($s['famille'] ?? 'neutre')) ?: 'neutre';
      $mois = round((float) $s['budget_ht'] / $nbMois, 2);
      $reelTtc = (float) ($s['reel_ttc'] ?? 0);
  ?>
    <tr class="prev-row prev-<?= e($fam) ?>" data-synth-idx="<?= (int) $i ?>">
      <td><strong><?= e($s['label']) ?></strong></td>
      <td class="num" data-synth="ht"><?= euro((float) $s['budget_ht']) ?></td>
      <td class="num muted" data-synth="mois"><?= euro($mois) ?></td>
      <td class="prev-gap"></td>
      <td class="num" data-synth="reel"><?= euro($reelTtc) ?></td>
    </tr>
  <?php endforeach; ?>
    <tr class="prev-row prev-marge">
      <td><strong>MARGE</strong></td>
      <td class="num">
        <span class="prev-marge-field">
          <input class="cell-input num prev-marge-input" id="prev-marge-pct"
                 value="<?= e($fmtPct($margePct)) ?>"
                 inputmode="decimal" aria-label="Marge en pourcentage"
                 title="Marge en % — OBJECTIF = total × (1 + marge %)">
          <span class="prev-marge-unit">%</span>
        </span>
      </td>
      <td class="num" data-marge-net><?= euro($margeNet) ?></td>
      <td class="prev-gap"></td>
      <td></td>
    </tr>
    <tr class="prev-objectif">
      <td><strong>OBJECTIF CA HT</strong></td>
      <td class="num" colspan="2" data-objectif><?= euro($objectif) ?></td>
      <td class="prev-gap"></td>
      <td></td>
    </tr>
  </tbody>
</table>
<?php endif; ?>

<script>
(function () {
  var grid = document.getElementById('previsionnel-grid');
  if (!grid) return;
  var saveUrl = grid.getAttribute('data-save-url') || '';
  var csrf = grid.getAttribute('data-csrf') || '';
  var nbMois = parseInt(grid.getAttribute('data-nb-mois') || '12', 10) || 12;
  var statusEl = document.getElementById('prev-status');

  function parseFr(v) {
    if (v == null || v === '') return 0;
    var n = parseFloat(String(v).replace(/[\s\u00a0\u202f€]/g, '').replace('−', '-').replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
  }
  function formatAmountInput(n) {
    if (!Number.isFinite(n) || Math.abs(n) < 0.005) return '';
    var neg = n < 0;
    var abs = Math.abs(n);
    var fixed = (Math.round(abs * 100) / 100).toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (neg ? '−' : '') + parts[0] + ',' + parts[1];
  }
  function euro(n) {
    if (n == null || !Number.isFinite(n)) return '—';
    return formatAmountInput(n) + ' €';
  }
  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg || '';
  }

  function readRow(tr) {
    var htEl = tr.querySelector('[data-field="ht"]');
    var tvaEl = tr.querySelector('[data-field="tva"]');
    var ttcEl = tr.querySelector('[data-field="ttc"]');
    return {
      htEl: htEl, tvaEl: tvaEl, ttcEl: ttcEl,
      ht: htEl ? parseFr(htEl.value) : 0,
      tva: tvaEl ? parseFr(tvaEl.value) : 0,
      ttc: ttcEl ? parseFr(ttcEl.value) : 0,
    };
  }

  function syncTtcFromParts(tr, field) {
    var r = readRow(tr);
    if (field === 'ttc') {
      var tva = Math.round((r.ttc - r.ht) * 100) / 100;
      if (r.tvaEl) r.tvaEl.value = formatAmountInput(tva);
      if (r.ttcEl) r.ttcEl.value = formatAmountInput(r.ttc);
      if (r.htEl) r.htEl.value = formatAmountInput(r.ht);
      return;
    }
    var ttc = Math.round((r.ht + r.tva) * 100) / 100;
    if (r.htEl) r.htEl.value = formatAmountInput(r.ht);
    if (r.tvaEl) r.tvaEl.value = formatAmountInput(r.tva);
    if (r.ttcEl) r.ttcEl.value = formatAmountInput(ttc);
  }

  function updateEcart(tr, ttc) {
    var reel = parseFr(tr.getAttribute('data-reel') || '0');
    var ecart = Math.round((ttc - reel) * 100) / 100;
    var cell = tr.querySelector('.prev-ecart');
    if (!cell) return;
    cell.textContent = euro(ecart);
    cell.classList.toggle('restant-neg', ecart < -0.005);
    cell.classList.toggle('restant-zero', ecart > 0.005);
  }

  function applyTotaux(totaux) {
    if (!totaux) return;
    var map = {
      ht: totaux.budget_ht,
      tva: totaux.budget_tva,
      ttc: totaux.budget_ttc,
      reel: totaux.reel,
      ecart: totaux.ecart,
    };
    Object.keys(map).forEach(function (k) {
      var el = grid.querySelector('[data-tot="' + k + '"]');
      if (el) el.textContent = euro(map[k]);
    });
  }

  function applySynthese(synthese, objectif, margeNet) {
    var root = document.getElementById('previsionnel-synth');
    if (!root) return;
    if (synthese) {
      synthese.forEach(function (s, i) {
        var tr = root.querySelector('[data-synth-idx="' + i + '"]');
        if (!tr) return;
        var ht = tr.querySelector('[data-synth="ht"]');
        var mois = tr.querySelector('[data-synth="mois"]');
        var reel = tr.querySelector('[data-synth="reel"]');
        if (ht) ht.textContent = euro(s.budget_ht);
        if (mois) mois.textContent = euro(Math.round((s.budget_ht / nbMois) * 100) / 100);
        if (reel) reel.textContent = euro(s.reel_ttc);
      });
    }
    var obj = root.querySelector('[data-objectif]');
    if (obj && objectif != null) obj.textContent = euro(objectif);
    var mn = root.querySelector('[data-marge-net]');
    if (mn && margeNet != null) mn.textContent = euro(margeNet);
  }

  function formatPctInput(n) {
    if (!Number.isFinite(n) || Math.abs(n) < 0.00005) return '0';
    var s = (Math.round(n * 100) / 100).toFixed(2).replace(/\.?0+$/, '');
    return s.replace('.', ',');
  }

  function saveMarge() {
    var inp = document.getElementById('prev-marge-pct');
    if (!inp) return;
    var pct = parseFr(inp.value);
    inp.value = formatPctInput(pct);
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_marge');
    fd.append('marge_pct', String(pct).replace('.', ','));
    setStatus('Enregistrement…');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setStatus((data && data.erreur) || 'Erreur');
          return;
        }
        if (data.marge_pct != null) inp.value = formatPctInput(data.marge_pct);
        applySynthese(data.synthese, data.objectif_ca_ht, data.marge_net);
        applyTotaux(data.totaux);
        setStatus('Enregistré');
        setTimeout(function () { setStatus(''); }, 1200);
      })
      .catch(function () { setStatus('Erreur réseau'); });
  }

  function saveRow(tr) {
    var code = tr.getAttribute('data-code');
    if (!code) return;
    var r = readRow(tr);
    var ttc = r.ttc;
    if (Math.abs(ttc) < 0.005 && (Math.abs(r.ht) > 0.005 || Math.abs(r.tva) > 0.005)) {
      ttc = Math.round((r.ht + r.tva) * 100) / 100;
    }
    updateEcart(tr, ttc);
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_budget');
    fd.append('categorie_code', code);
    fd.append('montant_ht', String(r.ht).replace('.', ','));
    fd.append('montant_tva', String(r.tva).replace('.', ','));
    fd.append('montant_ttc', String(ttc).replace('.', ','));
    setStatus('Enregistrement…');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setStatus((data && data.erreur) || 'Erreur');
          return;
        }
        if (data.ligne) {
          var l = data.ligne;
          if (r.htEl) r.htEl.value = formatAmountInput(l.budget_ht);
          if (r.tvaEl) r.tvaEl.value = formatAmountInput(l.budget_tva);
          if (r.ttcEl) r.ttcEl.value = formatAmountInput(l.budget_ttc);
          updateEcart(tr, l.budget_ttc);
          if (code === 'REM') {
            var netEl = tr.querySelector('[data-net-mensuel]');
            if (netEl) {
              var net = Math.round((l.budget_ht / nbMois) * 100) / 100;
              netEl.textContent = '(' + formatAmountInput(net) + ' € / mois)';
            }
          }
        }
        applyTotaux(data.totaux);
        applySynthese(data.synthese, data.objectif_ca_ht, data.marge_net);
        setStatus('Enregistré');
        setTimeout(function () { setStatus(''); }, 1200);
      })
      .catch(function () { setStatus('Erreur réseau'); });
  }

  grid.addEventListener('blur', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement) || !t.matches('.prev-budget')) return;
    var tr = t.closest('tr[data-code]');
    if (!tr) return;
    var field = t.getAttribute('data-field') || 'ht';
    syncTtcFromParts(tr, field);
    saveRow(tr);
  }, true);

  grid.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement) || !t.matches('.prev-budget')) return;
    if (ev.key === 'Enter') {
      ev.preventDefault();
      t.blur();
    }
  });

  var margeInp = document.getElementById('prev-marge-pct');
  if (margeInp) {
    margeInp.addEventListener('blur', saveMarge);
    margeInp.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        margeInp.blur();
      }
    });
  }
})();
</script>

<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Prévisionnel';
$page = 'previsionnel';
require dirname(__DIR__) . '/templates/layout.php';
