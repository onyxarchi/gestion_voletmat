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
    var s = String(raw)
      .replace(/[\s\u00a0\u202f]/g, '')
      .replace('−', '-')
      .replace(',', '.');
    if (s === '' || s === '—' || s === '-') return 0;
    // Retirer € / lettres (sinon Number('2250.00€') → NaN → totaux à 0)
    s = s.replace(/[^\d.\-]/g, '');
    if (s === '' || s === '-' || s === '.') return 0;
    var n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  /** Nombre FR : 1 234,56 (espace milliers ASCII). */
  function formatNbFr(n) {
    var neg = n < 0;
    var abs = Math.abs(n);
    var fixed = (Math.round(abs * 100) / 100).toFixed(2);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (neg ? '−' : '') + parts[0] + ',' + parts[1];
  }

  /** Toujours 2 décimales + espace milliers FR + € (ex. 1000 → 1 000,00 €). Vide reste vide. */
  function formatAmountInput(raw) {
    var s = String(raw == null ? '' : raw).replace(/[\s\u00a0\u202f€]/g, '').trim();
    if (s === '' || s === '—' || s === '-') return '';
    s = s.replace(',', '.');
    var n = Number(s);
    if (!Number.isFinite(n)) return String(raw);
    return formatNbFr(n) + ' €';
  }

  function normalizeAmountFields(tr) {
    tr.querySelectorAll('input.cell-input.num').forEach(function (inp) {
      if (document.activeElement === inp) return;
      inp.value = formatAmountInput(inp.value);
    });
  }

  function formatEuro(n) {
    if (n == null || !Number.isFinite(n)) return '—';
    return formatNbFr(n) + ' €';
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
    var td = select.closest('td.pl-mois');
    if (input) {
      var alerte = td && td.classList.contains('alerte-facture');
      input.className = 'cell-input num cell-mois txt-' + s + (alerte ? ' alerte-facture-input' : '');
    }
  }

  function clientTokens(s) {
    s = String(s || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toUpperCase();
    return s.split(/[^A-Z0-9]+/).filter(function (t) { return t.length >= 3; });
  }

  function clientsOverlap(a, b) {
    var ta = clientTokens(a);
    var tb = clientTokens(b);
    if (!ta.length || !tb.length) return false;
    for (var i = 0; i < ta.length; i++) {
      if (tb.indexOf(ta[i]) !== -1) return true;
    }
    return false;
  }

  function monthDiffYm(a, b) {
    if (!a || !b || a.length !== 6 || b.length !== 6) return 99;
    var ya = parseInt(a.slice(0, 4), 10);
    var ma = parseInt(a.slice(4), 10);
    var yb = parseInt(b.slice(0, 4), 10);
    var mb = parseInt(b.slice(4), 10);
    return Math.abs((ya * 12 + ma) - (yb * 12 + mb));
  }

  function findSubsetJs(items, target) {
    var n = items.length;
    var maxR = Math.min(6, n);
    var found = null;
    function walk(start, picked, sum) {
      if (found) return;
      if (picked.length >= 2 && Math.abs(sum - target) <= 0.02) {
        found = picked.slice();
        return;
      }
      if (picked.length >= maxR) return;
      for (var j = start; j < n; j++) {
        picked.push(j);
        walk(j + 1, picked, sum + items[j].ht);
        picked.pop();
        if (found) return;
      }
    }
    walk(0, [], 0);
    return found;
  }

  /** Recalcule encadrés rouges : bleu (payé) sans facture cohérente. */
  function refreshAlerteFactures() {
    var raw = grid.getAttribute('data-factures') || '[]';
    var factures;
    try {
      factures = JSON.parse(raw);
    } catch (e) {
      factures = [];
    }
    if (!Array.isArray(factures)) factures = [];

    var cells = [];
    grid.querySelectorAll('tbody tr[data-affaire-id]').forEach(function (tr) {
      var aid = tr.getAttribute('data-affaire-id');
      var clientEl = tr.querySelector('[name="client"]');
      var client = clientEl ? clientEl.value : '';
      tr.querySelectorAll('.mois-edit').forEach(function (wrap) {
        var inp = wrap.querySelector('.cell-mois');
        var sel = wrap.querySelector('select.cell-statut');
        var td = wrap.closest('td.pl-mois');
        if (!inp || !sel || !td) return;
        var m = (inp.name || '').match(/^mois\[(\d{6})\]$/);
        if (!m) return;
        var st = sel.value;
        var ht = Math.round(parseAmount(inp.value) * 100) / 100;
        if (st !== 'paye' || Math.abs(ht) < 0.005) {
          td.classList.remove('alerte-facture');
          inp.classList.remove('alerte-facture-input');
          return;
        }
        cells.push({
          key: aid + ':' + m[1],
          ym: m[1],
          ht: ht,
          client: client,
          td: td,
          inp: inp
        });
      });
    });

    var matched = {};
    var used = {};

    function assign1(maxDiff, requireClient) {
      cells.forEach(function (c) {
        if (matched[c.key]) return;
        var best = null;
        var bestScore = -1;
        factures.forEach(function (f) {
          if (used[f.id]) return;
          if (Math.abs(c.ht - Number(f.ht)) > 0.015) return;
          var d = monthDiffYm(c.ym, String(f.ym));
          if (d > maxDiff) return;
          var ov = clientsOverlap(c.client, f.client || '');
          if (requireClient && !ov) return;
          var score = (100 - d * 20) + (ov ? 30 : 0);
          if (score > bestScore) {
            bestScore = score;
            best = f.id;
          }
        });
        if (best != null) {
          matched[c.key] = true;
          used[best] = true;
        }
      });
    }

    assign1(0, true);
    assign1(0, false);

    // Sous-ensembles même mois
    var byM = {};
    cells.forEach(function (c, i) {
      if (matched[c.key]) return;
      (byM[c.ym] = byM[c.ym] || []).push(i);
    });
    factures.forEach(function (f) {
      if (used[f.id]) return;
      var idxs = (byM[f.ym] || []).filter(function (i) { return !matched[cells[i].key]; });
      if (idxs.length < 2) return;
      var items = idxs.map(function (i) { return cells[i]; });
      var comb = findSubsetJs(items, Number(f.ht));
      if (!comb) return;
      used[f.id] = true;
      comb.forEach(function (j) {
        matched[items[j].key] = true;
      });
    });

    assign1(1, true);
    assign1(1, false);

    var nAlert = 0;
    var nValide = 0;
    cells.forEach(function (c) {
      var sansFacture = !matched[c.key];
      var ecartOk = c.td.getAttribute('data-ecart-ok') === '1';
      // Validé = décision utilisateur définitive : on ne ré-alerte plus.
      var bad = sansFacture && !ecartOk;
      var valide = ecartOk;
      c.td.classList.toggle('alerte-facture', bad);
      c.td.classList.toggle('ecart-valide', valide);
      c.inp.classList.toggle('alerte-facture-input', bad);
      if (bad) {
        nAlert++;
        c.inp.title = 'Sans facture cohérente (à valider dans la bannière)';
      } else if (valide) {
        nValide++;
        c.inp.title = 'Recoupement validé — ne plus y revenir';
      }
      var sel = c.td.querySelector('select.cell-statut');
      if (sel) applyStatutColors(sel);
      if (bad) c.inp.classList.add('alerte-facture-input');
    });

    grid.querySelectorAll('td.pl-mois').forEach(function (td) {
      var sel = td.querySelector('select.cell-statut');
      var inp = td.querySelector('.cell-mois');
      var btn = td.querySelector('[data-ecart-ok-btn]');
      if (btn) btn.remove();
      if (!sel || sel.value !== 'paye' || !inp || Math.abs(parseAmount(inp.value)) < 0.005) {
        td.classList.remove('alerte-facture', 'ecart-valide');
        // Quitter « payé » annule la validation (nouvelle vie si repassé en bleu).
        td.setAttribute('data-ecart-ok', '0');
        if (inp) inp.classList.remove('alerte-facture-input');
      }
    });

    updateAlerteBanner(nAlert, nValide);
  }

  function updateAlerteBanner(nAlert, nValide) {
    var banner = document.querySelector('[data-alerte-banner]');
    var sc = document.querySelector('.planning-scroll');
    if (nAlert > 0) {
      if (!banner && sc && sc.parentNode) {
        banner = document.createElement('div');
        banner.setAttribute('data-alerte-banner', '');
        banner.setAttribute('role', 'status');
        sc.parentNode.insertBefore(banner, sc);
      }
      if (banner) {
        banner.className = 'planning-alerte-banner';
        banner.innerHTML =
          '<span class="planning-alerte-msg">' +
          '<span data-alerte-count>' + nAlert + '</span> montant' +
          (nAlert > 1 ? 's' : '') + ' bleu' + (nAlert > 1 ? 's' : '') +
          ' sans facture cohérente' +
          (nValide > 0 ? ' <span class="muted">(' + nValide + ' déjà validé' + (nValide > 1 ? 's' : '') + ')</span>' : '') +
          '</span>' +
          '<button type="button" class="btn-valider-recoupements" data-valider-ecarts ' +
          'title="Marquer les écarts restants comme OK — on n’y revient plus">' +
          'Valider les recoupements</button>';
      }
    } else if (banner) {
      banner.remove();
    }
  }

  function validerRecoupements() {
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'valider_ecarts');
    setStatus('Validation…', 'saving');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setStatus((data && data.erreur) || 'Échec', 'error');
          return;
        }
        grid.querySelectorAll('td.pl-mois.alerte-facture').forEach(function (td) {
          td.setAttribute('data-ecart-ok', '1');
          td.classList.remove('alerte-facture');
          td.classList.add('ecart-valide');
          var inp = td.querySelector('.cell-mois');
          if (inp) {
            inp.classList.remove('alerte-facture-input');
            inp.title = 'Écart validé';
          }
        });
        refreshAlerteFactures();
        setStatus('Recoupements validés', 'ok');
        setTimeout(function () {
          if (statusEl && statusEl.textContent === 'Recoupements validés') setStatus('');
        }, 1500);
      })
      .catch(function () {
        setStatus('Erreur réseau', 'error');
      });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-valider-ecarts]');
    if (!btn) return;
    ev.preventDefault();
    validerRecoupements();
  });

  function collectFormData(tr) {
    normalizeAmountFields(tr);
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
    var sumBleu = 0;
    var sumVrj = 0;
    var moisSums = {};

    grid.querySelectorAll('tbody tr[data-affaire-id]').forEach(function (tr) {
      sumContrat += parseAmount(tr.querySelector('[name="montant_contrat_ht"]')?.value);
      sumN1 += parseAmount(tr.querySelector('.col-n1')?.textContent);
      sumFact += parseAmount(tr.querySelector('[data-field="facture_en_n"]')?.textContent);
      sumRest += parseAmount(tr.querySelector('[data-field="restant_a_facturer"]')?.textContent);
      tr.querySelectorAll('.mois-edit').forEach(function (wrap) {
        var inp = wrap.querySelector('.cell-mois');
        if (!inp) return;
        var m = (inp.name || '').match(/^mois\[(\d{6})\]$/);
        var amt = parseAmount(inp.value);
        if (m) {
          moisSums[m[1]] = (moisSums[m[1]] || 0) + amt;
        }
        var sel = wrap.querySelector('select.cell-statut');
        var st = sel ? sel.value : 'a_facturer';
        if (st === 'paye') sumBleu += amt;
        else if (st === 'facture' || st === 'a_facturer' || st === 'litige') sumVrj += amt;
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
      cell.textContent = Math.abs(t) > 0.005 ? formatEuro(t) : '';
    });

    var kpiBleu = document.querySelector('[data-kpi="bleu"]');
    var kpiVrj = document.querySelector('[data-kpi="vrj"]');
    if (kpiBleu) kpiBleu.textContent = formatEuro(sumBleu);
    if (kpiVrj) kpiVrj.textContent = formatEuro(sumVrj);

    var objCard = document.querySelector('[data-objectif]');
    var statutCard = document.querySelector('[data-kpi="objectif-statut"]');
    if (objCard && statutCard) {
      var objectif = parseAmount(objCard.getAttribute('data-objectif'));
      // CA année − facturé en cours − restant à facturer
      var ecart = Math.round((objectif - sumBleu - sumVrj) * 100) / 100;
      var atteint = ecart <= 0.005;
      var labelEl = statutCard.querySelector('[data-kpi-label]');
      var valueEl = statutCard.querySelector('[data-kpi-value]');
      statutCard.classList.toggle('card-ok', atteint);
      statutCard.classList.toggle('card-ko', !atteint);
      if (labelEl) labelEl.textContent = atteint ? 'Objectif atteint' : 'Objectif manqué';
      if (valueEl) {
        valueEl.textContent = atteint
          ? ('+' + formatEuro(Math.abs(ecart)))
          : ('−' + formatEuro(ecart));
      }
    }

    refreshAlerteFactures();
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
          normalizeAmountFields(tr);
          updateFooters();
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
    if (t.matches('select.cell-statut') || t.matches('input.cell-mois')) {
      var tdM = t.closest('td.pl-mois');
      if (tdM) tdM.setAttribute('data-ecart-ok', '0');
    }
    if (t.matches('select.cell-statut')) {
      applyStatutColors(t);
      updateFooters();
    }
    var tr = t.closest('tr[data-affaire-id]');
    if (tr && (t.matches('select') || t.matches('input'))) {
      saveRow(tr, t.matches('select'));
    }
  });

  grid.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (t.classList.contains('cell-mois')) {
      var tdM = t.closest('td.pl-mois');
      if (tdM) tdM.setAttribute('data-ecart-ok', '0');
    }
    var tr = t.closest('tr[data-affaire-id]');
    if (tr) saveRow(tr, false);
  });

  grid.addEventListener('focusout', function (ev) {
    var t = ev.target;
    if (!(t instanceof HTMLInputElement)) return;
    var tr = t.closest('tr[data-affaire-id]');
    if (!tr) return;
    if (t.classList.contains('num')) {
      t.value = formatAmountInput(t.value);
    }
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

  // Affichage initial : toujours ,00 + KPIs
  grid.querySelectorAll('tbody tr[data-affaire-id]').forEach(normalizeAmountFields);
  updateFooters();

  grid.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.btn-del-ligne');
    if (!btn) return;
    var tr = btn.closest('tr[data-affaire-id]');
    if (!tr) return;
    var client = (tr.querySelector('[name="client"]') || {}).value || '';
    var ref = (tr.querySelector('[name="reference"]') || {}).value || '';
    var label = (ref + ' — ' + client).replace(/^\s*—\s*|\s*—\s*$/g, '').trim() || 'cette ligne';
    if (!window.confirm('Supprimer « ' + label + ' » ?\nCette action est définitive.')) {
      return;
    }
    var id = tr.getAttribute('data-affaire-id');
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'suppr_affaire');
    fd.append('affaire_id', id);
    setStatus('Suppression…', 'saving');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setStatus((data && data.erreur) || 'Suppression impossible', 'error');
          return;
        }
        var prev = tr.previousElementSibling;
        tr.remove();
        if (prev && prev.classList.contains('row-section')) {
          var next = prev.nextElementSibling;
          if (!next || next.classList.contains('row-section') || !next.getAttribute('data-affaire-id')) {
            prev.remove();
          }
        }
        updateFooters();
        setStatus('Ligne supprimée', 'ok');
        setTimeout(function () {
          if (statusEl && statusEl.textContent === 'Ligne supprimée') setStatus('');
        }, 1200);
      })
      .catch(function () {
        setStatus('Erreur réseau', 'error');
      });
  });

  /**
   * Scroll horizontal : mois en cours collé à gauche de la zone scrollable
   * (juste après les colonnes figées). getBoundingClientRect — pas offsetLeft
   * (peu fiable sur th sticky).
   */
  function scrollVersMoisCourant() {
    var sc = document.querySelector('.planning-scroll');
    if (!sc) return;
    var th = grid.querySelector('thead th.mois-courant');
    if (!th) return;
    var stickyEnd = grid.querySelector('thead th.col-del')
      || grid.querySelector('thead th.col-restant');
    if (!stickyEnd) return;

    var delta = th.getBoundingClientRect().left - stickyEnd.getBoundingClientRect().right;
    if (Math.abs(delta) < 0.5) return;
    var max = Math.max(0, sc.scrollWidth - sc.clientWidth);
    sc.scrollLeft = Math.max(0, Math.min(sc.scrollLeft + delta, max));
  }

  function planScrollMoisCourant() {
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        scrollVersMoisCourant();
        // Relancer après polices / layout flex (Synology / WebKit)
        setTimeout(scrollVersMoisCourant, 50);
        setTimeout(scrollVersMoisCourant, 250);
      });
    });
  }
  var scrollMoisResizeT = null;
  planScrollMoisCourant();
  window.addEventListener('load', scrollVersMoisCourant);
  window.addEventListener('resize', function () {
    clearTimeout(scrollMoisResizeT);
    scrollMoisResizeT = setTimeout(scrollVersMoisCourant, 100);
  });

  // Après création : aller sur la nouvelle ligne et focus client
  var hash = (location.hash || '').replace(/^#/, '');
  if (hash.indexOf('row-') === 0) {
    var rowNew = document.getElementById(hash);
    if (rowNew) {
      rowNew.scrollIntoView({ block: 'center', inline: 'nearest' });
      scrollVersMoisCourant();
      var clientInp = rowNew.querySelector('[name="client"]');
      if (clientInp) {
        try { clientInp.focus({ preventScroll: true }); } catch (e) { clientInp.focus(); }
        clientInp.select();
      }
    }
  }
})();

(function () {
  'use strict';
  var btn = document.getElementById('btn-nouvelle-ligne');
  if (!btn) return;
  var saveUrl = btn.getAttribute('data-save-url') || 'index.php?page=planning';
  var csrf = btn.getAttribute('data-csrf') || '';
  var statusEl = document.getElementById('planning-save-status');

  btn.addEventListener('click', function () {
    btn.disabled = true;
    if (statusEl) {
      statusEl.textContent = 'Création…';
      statusEl.className = 'planning-save-status is-saving';
    }
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', 'creer_affaire');
    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.affaire_id) {
          btn.disabled = false;
          if (statusEl) {
            statusEl.textContent = (data && data.erreur) || 'Création impossible';
            statusEl.className = 'planning-save-status is-error';
          }
          return;
        }
        window.location.href = saveUrl.replace(/#.*$/, '') +
          (saveUrl.indexOf('?') >= 0 ? '&' : '?') + 'new=1#row-' + data.affaire_id;
      })
      .catch(function () {
        btn.disabled = false;
        if (statusEl) {
          statusEl.textContent = 'Erreur réseau';
          statusEl.className = 'planning-save-status is-error';
        }
      });
  });
})();
