(function () {
  'use strict';

  var grid = document.getElementById('planning-grid');
  if (!grid) return;

  var saveUrl = grid.getAttribute('data-save-url') || 'index.php?page=planning';
  var csrf = grid.getAttribute('data-csrf') || '';
  var statusEl = document.getElementById('planning-save-status');
  var timers = {};
  var pending = {};

  function parseAmount(raw) {
    if (raw == null) return 0;
    var s = String(raw).replace(/\s|\u00a0/g, '').replace(',', '.');
    if (s === '' || s === '—') return 0;
    var n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  function formatEuro(n) {
    if (n == null || !Number.isFinite(n)) return '—';
    return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }

  function setStatus(text, kind) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.className = 'planning-save-status' + (kind ? ' is-' + kind : '');
  }

  function applyStatutColors(select) {
    var s = select.value;
    select.className = 'cell-statut txt-' + s;
    var wrap = select.closest('.mois-edit');
    var input = wrap && wrap.querySelector('.cell-mois');
    if (input) input.className = 'cell-input num cell-mois txt-' + s;
  }

  function collectFormData(tr) {
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'maj_affaire');
    fd.append('affaire_id', tr.getAttribute('data-affaire-id') || '');
    tr.querySelectorAll('input[name], select[name]').forEach(function (el) {
      fd.append(el.name, el.value);
    });
    return fd;
  }

  function updateFooters() {
    var sumContrat = 0;
    var sumN1 = 0;
    var sumFact = 0;
    var sumRest = 0;
    var moisSums = {};

    grid.querySelectorAll('tbody tr[data-affaire-id]').forEach(function (tr) {
      sumContrat += parseAmount(tr.querySelector('[name="montant_contrat_ht"]')?.value);
      sumN1 += parseAmount(tr.querySelector('.col-n1')?.textContent);
      sumFact += parseAmount(tr.querySelector('[data-field="facture_en_n"]')?.textContent);
      sumRest += parseAmount(tr.querySelector('[data-field="restant_a_facturer"]')?.textContent);
      tr.querySelectorAll('.cell-mois').forEach(function (inp) {
        var m = (inp.name || '').match(/^mois\[(\d{6})\]$/);
        if (!m) return;
        moisSums[m[1]] = (moisSums[m[1]] || 0) + parseAmount(inp.value);
      });
    });

    var footContrat = grid.querySelector('[data-foot="contrat"]');
    var footN1 = grid.querySelector('[data-foot="n1"]');
    var footFact = grid.querySelector('[data-foot="factn"]');
    var footRest = grid.querySelector('[data-foot="restant"]');
    if (footContrat) footContrat.textContent = formatEuro(sumContrat);
    if (footN1) footN1.textContent = formatEuro(sumN1);
    if (footFact) footFact.textContent = formatEuro(sumFact);
    if (footRest) footRest.textContent = formatEuro(sumRest);

    Object.keys(moisSums).forEach(function (key) {
      var cell = grid.querySelector('[data-foot-mois="' + key + '"]');
      if (!cell) return;
      var t = moisSums[key];
      cell.textContent = t > 0 ? formatEuro(t) : '';
    });

    var kpiFact = document.querySelector('[data-kpi="factn"]');
    var kpiRest = document.querySelector('[data-kpi="restant"]');
    if (kpiFact) kpiFact.textContent = formatEuro(sumFact);
    if (kpiRest) kpiRest.textContent = formatEuro(sumRest);
  }

  function saveRow(tr, immediate) {
    var id = tr.getAttribute('data-affaire-id');
    if (!id) return;
    if (timers[id]) {
      clearTimeout(timers[id]);
      timers[id] = null;
    }
    var run = function () {
      if (pending[id]) {
        pending[id].abort();
      }
      var ctrl = new AbortController();
      pending[id] = ctrl;
      tr.classList.add('row-saving');
      setStatus('Enregistrement…', 'saving');

      fetch(saveUrl, {
        method: 'POST',
        body: collectFormData(tr),
        headers: { Accept: 'application/json' },
        signal: ctrl.signal,
        credentials: 'same-origin',
      })
        .then(function (r) {
          return r.json().then(function (data) {
            return { okHttp: r.ok, data: data };
          });
        })
        .then(function (res) {
          if (pending[id] !== ctrl) return;
          pending[id] = null;
          tr.classList.remove('row-saving');
          if (!res.data || !res.data.ok) {
            setStatus((res.data && res.data.erreur) || 'Erreur d’enregistrement', 'error');
            tr.classList.add('row-save-error');
            setTimeout(function () { tr.classList.remove('row-save-error'); }, 2000);
            return;
          }
          var factCell = tr.querySelector('[data-field="facture_en_n"]');
          var restCell = tr.querySelector('[data-field="restant_a_facturer"]');
          if (factCell) factCell.textContent = res.data.facture_en_n_label;
          if (restCell) {
            restCell.textContent = res.data.restant_a_facturer_label;
            restCell.classList.remove('restant-zero', 'restant-neg');
            var r = res.data.restant_a_facturer;
            if (r != null && Math.abs(r) < 0.005) restCell.classList.add('restant-zero');
            else if (r != null && r < 0) restCell.classList.add('restant-neg');
          }
          var refInput = tr.querySelector('[name="reference"]');
          if (refInput && res.data.reference) refInput.value = res.data.reference;
          updateFooters();
          tr.classList.add('row-just-saved');
          setTimeout(function () { tr.classList.remove('row-just-saved'); }, 900);
          setStatus('Enregistré', 'ok');
          setTimeout(function () {
            if (statusEl && statusEl.textContent === 'Enregistré') setStatus('');
          }, 1200);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          pending[id] = null;
          tr.classList.remove('row-saving');
          setStatus('Erreur réseau', 'error');
        });
    };

    if (immediate) run();
    else timers[id] = setTimeout(run, 350);
  }

  grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.matches('select.cell-statut')) {
      applyStatutColors(t);
    }
    var tr = t.closest('tr[data-affaire-id]');
    if (tr && (t.matches('select') || t.matches('input'))) {
      saveRow(tr, t.matches('select'));
    }
  });

  grid.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    var tr = t.closest('tr[data-affaire-id]');
    if (tr) saveRow(tr, false);
  });

  grid.addEventListener('focusout', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    var tr = t.closest('tr[data-affaire-id]');
    if (!tr) return;
    var next = ev.relatedTarget;
    if (next && tr.contains(next)) return;
    saveRow(tr, true);
  });

  // Entrée = valider le champ courant
  grid.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    ev.preventDefault();
    t.blur();
  });
})();
