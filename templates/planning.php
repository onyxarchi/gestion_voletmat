<?php
declare(strict_types=1);
/** @var array|null $exercice */
/** @var array $planning */
ob_start();

$mois = $planning['mois'] ?? [];
$lignes = $planning['lignes'] ?? [];
$totauxMois = $planning['totaux_mois'] ?? [];
$totauxStatut = $planning['totaux_statut'] ?? [];
$totauxSynthese = $planning['totaux_synthese'] ?? [
    'contrat' => 0.0,
    'encaisse_n1' => 0.0,
    'facture_en_n' => 0.0,
    'restant_a_facturer' => 0.0,
];
$csrf = csrf_token();
$saveUrl = page_url('planning');
?>
<div class="planning-top">
  <h1>Planning facturation</h1>
  <?php if ($exercice): ?>
    <span class="planning-ex-tag" title="Selon la date du jour"><?= e($exercice['code']) ?></span>
  <?php endif; ?>
  <span class="planning-save-status" id="planning-save-status" aria-live="polite"></span>
</div>

<?php if (!$exercice): ?>
  <p class="lead">Aucun exercice pour la date du jour.</p>
<?php elseif (!$lignes): ?>
  <div class="empty">Aucune affaire pour cet exercice. Relancez l’import N4/N5 si besoin.</div>
<?php else: ?>
<div class="planning-scroll">
  <table class="data planning-grid" id="planning-grid"
         data-save-url="<?= e($saveUrl) ?>"
         data-csrf="<?= e($csrf) ?>">
    <thead>
      <tr>
        <th class="sticky-col col-ref">Réf.</th>
        <th class="sticky-col col-client">Client</th>
        <th class="num sticky-col col-contrat">Contrat HT</th>
        <th class="num sticky-col col-n1" title="Encaissé sur exercices précédents">Encaissé N−1</th>
        <th class="num sticky-col col-factn" title="Somme des montants mensuels de la ligne">Facturé en N</th>
        <th class="num sticky-col col-restant sticky-end" title="Contrat HT − encaissé N−1 − facturé en N">À facturer</th>
        <?php foreach ($mois as $m): ?>
          <th class="num col-mois"><?= e($m['label']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php
      $groupeCourant = null;
      $nbMois = count($mois);
      foreach ($lignes as $ligne):
      $groupe = (string) ($ligne['groupe'] ?? 'contrat');
      if ($groupe !== $groupeCourant):
          $groupeCourant = $groupe;
    ?>
      <tr class="row-section">
        <td class="sticky-col col-ref" colspan="2">
          <strong><?= e(\Voletmat\PlanningService::groupeLabel($groupe)) ?></strong>
        </td>
        <td class="sticky-col col-contrat" colspan="4"></td>
        <?php if ($nbMois > 0): ?>
          <td colspan="<?= (int) $nbMois ?>"></td>
        <?php endif; ?>
      </tr>
    <?php
      endif;
      $rowClass = ['row-groupe-' . $groupe];
      $rowClassAttr = ' class="' . e(implode(' ', $rowClass)) . '"';
      $restant = $ligne['restant_a_facturer'];
      $restantClass = '';
      if ($restant !== null && abs((float) $restant) < 0.005) {
          $restantClass = ' restant-zero';
      } elseif ($restant !== null && (float) $restant < 0) {
          $restantClass = ' restant-neg';
      }
      $aid = (int) $ligne['id'];
      $rowId = 'row-' . $aid;
      $contratVal = $ligne['montant_contrat_ht'];
      $contratStr = $contratVal === null ? '' : number_format((float) $contratVal, 2, ',', '');
    ?>
      <tr id="<?= e($rowId) ?>" data-affaire-id="<?= $aid ?>"<?= $rowClassAttr ?>>
        <td class="sticky-col col-ref">
          <input class="cell-input" type="text" name="reference"
                 value="<?= e((string) ($ligne['reference'] ?? '')) ?>" autocomplete="off">
        </td>
        <td class="sticky-col col-client">
          <input class="cell-input" type="text" name="client"
                 value="<?= e($ligne['client']) ?>" required autocomplete="organization">
        </td>
        <td class="num sticky-col col-contrat">
          <input class="cell-input num" type="text" name="montant_contrat_ht"
                 value="<?= e($contratStr) ?>" inputmode="decimal" placeholder="0,00">
        </td>
        <td class="num sticky-col col-n1"><?= euro((float) $ligne['encaisse_n1']) ?></td>
        <td class="num sticky-col col-factn" data-field="facture_en_n"><?= euro((float) $ligne['facture_en_n']) ?></td>
        <td class="num sticky-col col-restant sticky-end<?= $restantClass ?>" data-field="restant_a_facturer"><?= euro($restant) ?></td>
        <?php foreach ($mois as $m):
            $cell = $ligne['cellules'][$m['key']] ?? null;
            $st = $cell ? (preg_replace('/[^a-z_]/', '', $cell['statut']) ?: 'a_facturer') : 'a_facturer';
            if (!in_array($st, ['a_facturer', 'facture', 'litige', 'paye'], true)) {
                $st = 'a_facturer';
            }
            $moisVal = $cell ? number_format((float) $cell['montant_ht'], 2, ',', '') : '';
        ?>
          <td class="num pl-mois">
            <div class="mois-edit">
              <input class="cell-input num cell-mois txt-<?= e($st) ?>"
                     type="text" name="mois[<?= e($m['key']) ?>]"
                     value="<?= e($moisVal) ?>" inputmode="decimal"
                     placeholder="—"
                     title="<?= e($m['label']) ?>">
              <select class="cell-statut txt-<?= e($st) ?>"
                      name="statut[<?= e($m['key']) ?>]"
                      title="R à facturer · V facturé · J litige · B payé"
                      aria-label="Statut couleur">
                <option value="facture" <?= $st === 'facture' ? 'selected' : '' ?>>V</option>
                <option value="a_facturer" <?= $st === 'a_facturer' ? 'selected' : '' ?>>R</option>
                <option value="litige" <?= $st === 'litige' ? 'selected' : '' ?>>J</option>
                <option value="paye" <?= $st === 'paye' ? 'selected' : '' ?>>B</option>
              </select>
            </div>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="sticky-col col-ref" colspan="2"><strong>Totaux</strong></td>
        <td class="num sticky-col col-contrat" data-foot="contrat"><?= euro($totauxSynthese['contrat']) ?></td>
        <td class="num sticky-col col-n1" data-foot="n1"><?= euro($totauxSynthese['encaisse_n1']) ?></td>
        <td class="num sticky-col col-factn" data-foot="factn"><?= euro($totauxSynthese['facture_en_n']) ?></td>
        <td class="num sticky-col col-restant sticky-end" data-foot="restant"><?= euro($totauxSynthese['restant_a_facturer']) ?></td>
        <?php foreach ($mois as $m):
            $t = (float) ($totauxMois[$m['key']] ?? 0);
        ?>
          <td class="num" data-foot-mois="<?= e($m['key']) ?>"><?= $t > 0 ? euro($t) : '' ?></td>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
</div>

<aside class="planning-footer">
  <div class="legend">
    <span class="leg leg-facture">V texte vert = facturé</span>
    <span class="leg leg-a_facturer">R texte rouge = à facturer</span>
    <span class="leg leg-litige">J texte jaune = litige</span>
    <span class="leg leg-paye">B texte bleu = payé</span>
  </div>
  <div class="cards cards-compact">
    <div class="card">
      <div class="label">Affaires</div>
      <div class="value"><?= count($lignes) ?></div>
    </div>
    <div class="card">
      <div class="label">Encaissé N−1</div>
      <div class="value" data-kpi="n1"><?= euro($totauxSynthese['encaisse_n1'] ?? 0) ?></div>
    </div>
    <div class="card">
      <div class="label">Facturé en N</div>
      <div class="value" data-kpi="factn"><?= euro($totauxSynthese['facture_en_n'] ?? 0) ?></div>
    </div>
    <div class="card">
      <div class="label">À facturer</div>
      <div class="value" data-kpi="restant"><?= euro($totauxSynthese['restant_a_facturer'] ?? 0) ?></div>
    </div>
  </div>
  <?php if ($exercice): ?>
  <p class="planning-note">
    <?= e($exercice['libelle']) ?>
    (<?= date_fr($exercice['date_debut']) ?> → <?= date_fr($exercice['date_fin']) ?>)
    · Enregistrement automatique à chaque modification
    · Lettre à côté du montant : V/R/J/B
    · À facturer = contrat HT − encaissé N−1 − facturé en N
  </p>
  <?php endif; ?>
</aside>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Planning facturation';
$page = 'planning';
$pageScripts = ['assets/js/planning.js'];
require dirname(__DIR__) . '/templates/layout.php';
