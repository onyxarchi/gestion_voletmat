<?php
declare(strict_types=1);
/** @var array|null $exercice */
/** @var array $planning */
/** @var array|null $objectifInfo */
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
$facturesRapp = $planning['factures_rapprochement'] ?? [];
$nbAlertesFacture = (int) ($planning['nb_alertes_facture'] ?? 0);
$nbAlertesValidees = (int) ($planning['nb_alertes_validees'] ?? 0);
$csrf = csrf_token();
$saveUrl = page_url('planning');
$titrePlanning = 'Planning Facturation';
$moisCourantKey = date('Ym');
$moisKeys = array_column($mois, 'key');
if ($moisKeys) {
    if ($moisCourantKey < $moisKeys[0]) {
        $moisCourantKey = $moisKeys[0];
    } elseif ($moisCourantKey > $moisKeys[count($moisKeys) - 1]) {
        $moisCourantKey = $moisKeys[count($moisKeys) - 1];
    }
}
?>
<div class="planning-top">
  <h1><?= e($titrePlanning) ?></h1>
  <?php if ($exercice): ?>
  <button type="button" class="btn btn-nouvelle-ligne" id="btn-nouvelle-ligne"
          data-save-url="<?= e($saveUrl) ?>"
          data-csrf="<?= e($csrf) ?>">+ Nouvelle ligne</button>
  <?php endif; ?>
  <?php if ($exercice && $lignes): ?>
  <div class="legend legend-inline">
    <span class="leg leg-facture">V vert = facturé</span>
    <span class="leg leg-a_facturer">R rouge = à facturer</span>
    <span class="leg leg-litige">J jaune = litige</span>
    <span class="leg leg-paye">B bleu = payé</span>
    <span class="leg leg-alerte-facture" title="Montant bleu sans facture correspondante (onglet Facturation)">⚠ bleu ≠ facture</span>
  </div>
  <?php endif; ?>
  <span class="planning-save-status" id="planning-save-status" aria-live="polite"></span>
</div>

<?php if (!$exercice): ?>
  <p class="lead">Aucun exercice pour la date du jour.</p>
<?php elseif (!$lignes): ?>
  <div class="empty">Aucune affaire pour cet exercice. Cliquez sur <strong>+ Nouvelle ligne</strong> pour commencer, ou relancez l’import N4/N5.</div>
<?php else: ?>
<?php if ($nbAlertesFacture > 0): ?>
  <div class="planning-alerte-banner" role="status" data-alerte-banner>
    <span class="planning-alerte-msg">
      <span data-alerte-count><?= (int) $nbAlertesFacture ?></span> montant<?= $nbAlertesFacture > 1 ? 's' : '' ?> bleu<?= $nbAlertesFacture > 1 ? 's' : '' ?>
      sans facture cohérente
      <?php if ($nbAlertesValidees > 0): ?>
        <span class="muted"> (<?= (int) $nbAlertesValidees ?> déjà validé<?= $nbAlertesValidees > 1 ? 's' : '' ?>)</span>
      <?php endif; ?>
    </span>
    <button type="button" class="btn-valider-recoupements" data-valider-ecarts
            title="Marquer les écarts restants comme OK (corrigés ou normaux)">
      Valider les recoupements
    </button>
  </div>
<?php endif; ?>
<div class="planning-scroll">
  <table class="data planning-grid" id="planning-grid"
         data-save-url="<?= e($saveUrl) ?>"
         data-csrf="<?= e($csrf) ?>"
         data-factures="<?= e(json_encode($facturesRapp, JSON_UNESCAPED_UNICODE) ?: '[]') ?>">
    <thead>
      <tr>
        <th class="sticky-col col-ref">Réf.</th>
        <th class="sticky-col col-client">Client</th>
        <th class="num sticky-col col-contrat">Contrat HT</th>
        <th class="num sticky-col col-n1" title="Encaissé sur exercices précédents">Encaissé N−1</th>
        <th class="num sticky-col col-factn" title="Somme des montants mensuels de la ligne">Facturé en N</th>
        <th class="num sticky-col col-restant" title="Contrat HT − encaissé N−1 − facturé en N">À facturer</th>
        <th class="sticky-col col-del sticky-end" title="Supprimer la ligne"></th>
        <?php foreach ($mois as $m): ?>
          <th class="num col-mois<?= $m['key'] === $moisCourantKey ? ' mois-courant' : '' ?>"
              data-mois="<?= e($m['key']) ?>"><?= e($m['label']) ?></th>
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
        <td class="sticky-col col-contrat" colspan="5"></td>
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
      $contratStr = $contratVal === null ? '' : number_format((float) $contratVal, 2, ',', ' ') . ' €';
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
        <td class="num sticky-col col-restant<?= $restantClass ?>" data-field="restant_a_facturer"><?= euro($restant) ?></td>
        <td class="sticky-col col-del sticky-end">
          <button type="button" class="btn-del-ligne" title="Supprimer cette ligne" aria-label="Supprimer">×</button>
        </td>
        <?php foreach ($mois as $m):
            $cell = $ligne['cellules'][$m['key']] ?? null;
            $st = $cell ? (preg_replace('/[^a-z_]/', '', $cell['statut']) ?: 'a_facturer') : 'a_facturer';
            if (!in_array($st, ['a_facturer', 'facture', 'litige', 'paye'], true)) {
                $st = 'a_facturer';
            }
            $moisVal = $cell ? number_format((float) $cell['montant_ht'], 2, ',', ' ') . ' €' : '';
            $alerteFacture = $cell && $st === 'paye' && !empty($cell['alerte_facture']);
            $ecartValide = $cell && $st === 'paye' && !empty($cell['ecart_valide']);
        ?>
          <td class="num pl-mois<?= $m['key'] === $moisCourantKey ? ' mois-courant' : '' ?><?= $alerteFacture ? ' alerte-facture' : '' ?><?= $ecartValide ? ' ecart-valide' : '' ?>"
              data-mois="<?= e($m['key']) ?>"
              data-ecart-ok="<?= $ecartValide ? '1' : '0' ?>">
            <div class="mois-edit">
              <input class="cell-input num cell-mois txt-<?= e($st) ?><?= $alerteFacture ? ' alerte-facture-input' : '' ?>"
                     type="text" name="mois[<?= e($m['key']) ?>]"
                     value="<?= e($moisVal) ?>" inputmode="decimal"
                     placeholder="—"
                     title="<?= $alerteFacture ? 'Sans facture cohérente (à valider dans la bannière)' : ($ecartValide ? 'Écart validé' : e($m['label'])) ?>">
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
        <td class="num sticky-col col-restant" data-foot="restant"><?= euro($totauxSynthese['restant_a_facturer']) ?></td>
        <td class="sticky-col col-del sticky-end"></td>
        <?php foreach ($mois as $m):
            $t = (float) ($totauxMois[$m['key']] ?? 0);
        ?>
          <td class="num" data-foot-mois="<?= e($m['key']) ?>"><?= abs($t) > 0.005 ? euro($t) : '' ?></td>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
</div>

<aside class="planning-footer">
  <div class="cards cards-compact">
    <div class="card">
      <div class="label">Montant facturé (en cours)</div>
      <div class="value" data-kpi="bleu"><?= euro((float) ($totauxStatut['paye'] ?? 0)) ?></div>
    </div>
    <div class="card">
      <div class="label">Montant restant à facturer</div>
      <div class="value" data-kpi="vrj"><?= euro(
          (float) ($totauxStatut['a_facturer'] ?? 0)
          + (float) ($totauxStatut['facture'] ?? 0)
          + (float) ($totauxStatut['litige'] ?? 0)
      ) ?></div>
    </div>
    <?php if (!empty($objectifInfo)): ?>
    <div class="card" data-objectif="<?= e((string) $objectifInfo['objectif']) ?>">
      <div class="label">Objectif de l’exercice</div>
      <div class="value"><?= euro((float) $objectifInfo['objectif']) ?></div>
    </div>
    <div class="card <?= !empty($objectifInfo['atteint']) ? 'card-ok' : 'card-ko' ?>" data-kpi="objectif-statut">
      <div class="label" data-kpi-label><?= !empty($objectifInfo['atteint']) ? 'Objectif atteint' : 'Objectif manqué' ?></div>
      <div class="value" data-kpi-value>
        <?php if (!empty($objectifInfo['atteint'])): ?>
          +<?= euro(abs((float) $objectifInfo['ecart'])) ?>
        <?php else: ?>
          −<?= euro((float) $objectifInfo['ecart']) ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</aside>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = $titrePlanning;
$page = 'planning';
$pageScripts = ['assets/js/planning.js'];
require dirname(__DIR__) . '/templates/layout.php';
