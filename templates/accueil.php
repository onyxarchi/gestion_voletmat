<?php
declare(strict_types=1);
/** @var array $stats */
/** @var array|null $exercice */
/** @var array $ca */
ob_start();

$progression = $ca['progression'] ?? [];
$annees = $ca['annees'] ?? [];
$mensuel = $ca['mensuel'] ?? [];

$chartProgression = [
    'labels' => array_column($progression, 'label'),
    'values' => array_map(static fn ($r) => round((float) $r['ca_ht'], 2), $progression),
    'evolutions' => array_map(
        static fn ($r) => $r['evolution'] === null ? null : round((float) $r['evolution'] * 100, 1),
        $progression
    ),
];
$chartAnnees = [
    'labels' => array_map(static fn ($r) => (string) $r['annee'], $annees),
    'janv_juin' => array_map(static fn ($r) => round((float) $r['janv_juin'], 2), $annees),
    'juil_dec' => array_map(static fn ($r) => round((float) $r['juil_dec'], 2), $annees),
];
$chartMensuel = [
    'labels' => array_column($mensuel, 'label'),
    'values' => array_map(static fn ($r) => round((float) $r['ca_ht'], 2), $mensuel),
];

$evo = $stats['ca_evolution'] ?? null;
$evoLabel = $evo === null ? '—' : (($evo >= 0 ? '+' : '') . number_format($evo * 100, 1, ',', ' ') . ' %');
?>
<h1>Progression CA</h1>
<?php if ($exercice): ?>
  <p class="lead">
    Exercice en cours : <strong><?= e($exercice['libelle']) ?></strong>
    (<?= date_fr($exercice['date_debut']) ?> → <?= date_fr($exercice['date_fin']) ?>)
  </p>
<?php else: ?>
  <p class="lead">Aucun exercice pour la date du jour. Voir <a href="<?= e(page_url('exercices')) ?>">Exercices</a>.</p>
<?php endif; ?>

<div class="cards">
  <div class="card">
    <div class="label">CA période (barre courante)</div>
    <div class="value"><?= euro($stats['ca_progression'] ?? null) ?></div>
  </div>
  <div class="card">
    <div class="label">Évolution vs N−1</div>
    <div class="value"><?= e($evoLabel) ?></div>
  </div>
  <div class="card">
    <div class="label">CA HT facturé (exercice)</div>
    <div class="value"><?= euro($stats['ca_ht'] ?? null) ?></div>
  </div>
  <div class="card">
    <div class="label">Factures (exercice)</div>
    <div class="value"><?= (int) ($stats['nb_factures'] ?? 0) ?></div>
  </div>
</div>

<div class="panel chart-panel">
  <h2 style="margin-top:0">CA — Vol&amp;Mat</h2>
  <p class="chart-hint">
    Historique juil.–juin (12 mois) jusqu’à 2024-25 ·
    <strong>2025-26 = exercice long</strong> juil. 2025 → déc. 2026 (18 mois) ·
    puis <strong>années civiles</strong> à partir de 2027.
    Les % comparent des périodes de durées différentes.
  </p>
  <div class="chart-wrap">
    <canvas id="chart-progression" aria-label="Graphique progression CA"></canvas>
  </div>
  <?php if ($progression): ?>
  <div class="evo-row">
    <?php foreach ($progression as $p): ?>
      <div class="evo-cell">
        <span class="evo-label"><?= e($p['label']) ?></span>
        <span class="evo-val"><?= euro((float) $p['ca_ht']) ?></span>
        <?php if ($p['evolution'] !== null): ?>
          <span class="evo-pct <?= $p['evolution'] >= 0 ? 'up' : 'down' ?>">
            <?= ($p['evolution'] >= 0 ? '+' : '') . number_format($p['evolution'] * 100, 1, ',', ' ') ?> %
          </span>
        <?php else: ?>
          <span class="evo-pct muted">—</span>
        <?php endif; ?>
        <?php
          $kindLabel = match ($p['kind'] ?? '') {
              'fiscal_juil_juin' => '12 mois (juil.–juin)',
              'exercice_long' => '18 mois',
              'annee_civile' => 'année civile',
              default => '',
          };
        ?>
        <?php if ($kindLabel !== ''): ?>
          <span class="evo-kind"><?= e($kindLabel) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="ca-grid">
  <div class="panel chart-panel">
    <h2 style="margin-top:0">Historique par année civile</h2>
    <p class="chart-hint">Janv.–juin · Juil.–déc.</p>
    <div class="chart-wrap chart-wrap-sm">
      <canvas id="chart-annees" aria-label="Graphique CA par semestre"></canvas>
    </div>
  </div>

  <div class="panel">
    <h2 style="margin-top:0">Détail semestres</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Année</th>
          <th class="num">Janv. / juin</th>
          <th class="num">Juil. / déc.</th>
          <th class="num">Année</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($annees as $row): ?>
          <tr>
            <td>CA <?= (int) $row['annee'] ?></td>
            <td class="num"><?= euro((float) $row['janv_juin']) ?></td>
            <td class="num"><?= euro((float) $row['juil_dec']) ?></td>
            <td class="num"><strong><?= euro((float) $row['annee_totale']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$annees): ?>
          <tr><td colspan="4" class="muted">Aucune donnée CA.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($mensuel): ?>
<div class="panel chart-panel">
  <h2 style="margin-top:0">CA mensuel (exercice actif)</h2>
  <div class="chart-wrap chart-wrap-sm">
    <canvas id="chart-mensuel" aria-label="Graphique CA mensuel"></canvas>
  </div>
</div>
<?php endif; ?>

<script type="application/json" id="ca-chart-data"><?= json_encode([
    'progression' => $chartProgression,
    'annees' => $chartAnnees,
    'mensuel' => $chartMensuel,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
$content = ob_get_clean();
$title = 'Progression CA';
$page = 'accueil';
$pageScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js', 'assets/js/ca-dashboard.js'];
$pageInlineScript = null;
require dirname(__DIR__) . '/templates/layout.php';
