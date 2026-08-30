(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function euroTick(value) {
    if (value >= 1000) {
      return (value / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' k€';
    }
    return value.toLocaleString('fr-FR') + ' €';
  }

  ready(function () {
    var el = document.getElementById('ca-chart-data');
    if (!el || typeof Chart === 'undefined') return;
    var data;
    try {
      data = JSON.parse(el.textContent || '{}');
    } catch (e) {
      return;
    }

    var blue = '#507890';
    var blueMid = '#6a9bb0';
    var coral = '#d85860';
    var ink = '#1c2428';
    var muted = '#5a6870';

    Chart.defaults.font.family = '"Segoe UI", "Helvetica Neue", Arial, sans-serif';
    Chart.defaults.color = muted;

    var prog = data.progression || {};
    var canvasProg = document.getElementById('chart-progression');
    if (canvasProg && prog.labels && prog.labels.length) {
      var progColors = (prog.labels || []).map(function (_, i) {
        return (prog.en_cours || [])[i] ? coral : blue;
      });
      new Chart(canvasProg, {
        type: 'bar',
        data: {
          labels: prog.labels,
          datasets: [{
            label: 'CA HT',
            data: prog.values,
            backgroundColor: progColors,
            borderRadius: 4,
            maxBarThickness: 64,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            title: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var v = ctx.parsed.y || 0;
                  var evo = (prog.evolutions || [])[ctx.dataIndex];
                  var line = v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
                  if (evo !== null && evo !== undefined) {
                    line += ' (' + (evo >= 0 ? '+' : '') + evo.toLocaleString('fr-FR') + ' %)';
                  }
                  return line;
                },
              },
            },
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: ink } },
            y: {
              beginAtZero: true,
              ticks: { callback: euroTick },
              grid: { color: '#e8eef2' },
            },
          },
        },
      });
    }

    var ann = data.annees || {};
    var canvasAnn = document.getElementById('chart-annees');
    if (canvasAnn && ann.labels && ann.labels.length) {
      var annEnCours = ann.en_cours || [];
      var annBlue = ann.labels.map(function (_, i) {
        return annEnCours[i] ? '#3a6a82' : blue;
      });
      var annCoral = ann.labels.map(function (_, i) {
        return annEnCours[i] ? '#b84850' : coral;
      });
      new Chart(canvasAnn, {
        type: 'bar',
        data: {
          labels: ann.labels,
          datasets: [
            {
              label: 'Janv. / juin',
              data: ann.janv_juin,
              backgroundColor: annBlue,
              borderRadius: 3,
              maxBarThickness: 40,
            },
            {
              label: 'Juil. / déc.',
              data: ann.juil_dec,
              backgroundColor: annCoral,
              borderRadius: 3,
              maxBarThickness: 40,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var v = ctx.parsed.y || 0;
                  return ctx.dataset.label + ' : ' +
                    v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
                },
              },
            },
          },
          scales: {
            x: { stacked: true, grid: { display: false } },
            y: {
              stacked: true,
              beginAtZero: true,
              ticks: { callback: euroTick },
              grid: { color: '#e8eef2' },
            },
          },
        },
      });
    }

    var men = data.mensuel || {};
    var canvasMen = document.getElementById('chart-mensuel');
    if (canvasMen && men.labels && men.labels.length) {
      new Chart(canvasMen, {
        type: 'bar',
        data: {
          labels: men.labels,
          datasets: [{
            label: 'CA HT',
            data: men.values,
            backgroundColor: blueMid,
            borderRadius: 3,
            maxBarThickness: 36,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var v = ctx.parsed.y || 0;
                  return v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
                },
              },
            },
          },
          scales: {
            x: { grid: { display: false } },
            y: {
              beginAtZero: true,
              ticks: { callback: euroTick },
              grid: { color: '#e8eef2' },
            },
          },
        },
      });
    }
  });
})();
