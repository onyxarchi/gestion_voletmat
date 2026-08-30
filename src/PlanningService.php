<?php
declare(strict_types=1);

namespace Voletmat;

use PDO;

/**
 * Grille Planning facturation (légende Excel : rouge / jaune / bleu / vert).
 */
final class PlanningService
{
    private const MOIS_FR = [
        '01' => 'janv.', '02' => 'févr.', '03' => 'mars', '04' => 'avr.',
        '05' => 'mai', '06' => 'juin', '07' => 'juil.', '08' => 'août',
        '09' => 'sept.', '10' => 'oct.', '11' => 'nov.', '12' => 'déc.',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   mois: list<array{key:string, label:string}>,
     *   lignes: list<array{
     *     id:int, reference:?string, client:string, type:string,
     *     montant_contrat_ht:?float, encaisse_n1:float, fini:bool,
     *     total_planifie:float,
     *     cellules: array<string, array{montant_ht:float, statut:string, couleur:?string}>
     *   }>,
     *   totaux_mois: array<string, float>,
     *   totaux_statut: array<string, float>
     * }
     */
    public function grille(int $exerciceId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, reference, client, type, montant_contrat_ht, encaisse_n1, COALESCE(fini, 0) AS fini
             FROM affaires WHERE exercice_id = ? ORDER BY
               CASE type WHEN \'contrat\' THEN 1 WHEN \'mission\' THEN 2 WHEN \'stripe\' THEN 3 WHEN \'pv\' THEN 4 ELSE 5 END,
               reference COLLATE NOCASE, client COLLATE NOCASE'
        );
        $st->execute([$exerciceId]);
        $affaires = $st->fetchAll();

        $st = $this->pdo->prepare(
            'SELECT e.affaire_id, e.annee_mois, e.montant_ht, e.statut, e.couleur,
                    COALESCE(e.ecart_ok, 0) AS ecart_ok
             FROM echeances_facturation e
             INNER JOIN affaires a ON a.id = e.affaire_id
             WHERE a.exercice_id = ?
             ORDER BY e.annee_mois'
        );
        $st->execute([$exerciceId]);
        $byAffaire = [];
        $moisSet = [];
        foreach ($st->fetchAll() as $e) {
            $aid = (int) $e['affaire_id'];
            $m = (string) $e['annee_mois'];
            $moisSet[$m] = true;
            $byAffaire[$aid][$m] = [
                'montant_ht' => (float) $e['montant_ht'],
                'statut' => (string) $e['statut'],
                'couleur' => $e['couleur'] !== null ? (string) $e['couleur'] : null,
                'ecart_ok' => (int) $e['ecart_ok'] === 1,
            ];
        }

        // Mois de l’exercice même sans échéance (grille complète)
        $ex = $this->pdo->prepare('SELECT date_debut, date_fin FROM exercices WHERE id = ?');
        $ex->execute([$exerciceId]);
        $exRow = $ex->fetch();
        if ($exRow) {
            foreach ($this->monthsBetween((string) $exRow['date_debut'], (string) $exRow['date_fin']) as $m) {
                $moisSet[$m] = true;
            }
        }
        $moisKeys = array_map('strval', array_keys($moisSet));
        sort($moisKeys);

        $mois = [];
        foreach ($moisKeys as $key) {
            $mois[] = ['key' => $key, 'label' => $this->labelMois($key)];
        }

        $totauxMois = array_fill_keys($moisKeys, 0.0);
        $totauxStatut = [
            'a_facturer' => 0.0,
            'facture' => 0.0,
            'litige' => 0.0,
            'paye' => 0.0,
        ];

        $lignes = [];
        foreach ($affaires as $a) {
            $aid = (int) $a['id'];
            $groupe = self::groupeAffaire((string) $a['type'], $a['reference'], false);
            $isMar = $groupe === 'mar';
            $cellules = $byAffaire[$aid] ?? [];
            $total = 0.0;
            foreach ($cellules as $m => $c) {
                $total += $c['montant_ht'];
                if (isset($totauxMois[$m])) {
                    $totauxMois[$m] += $c['montant_ht'];
                }
                $stKey = $c['statut'];
                if (!isset($totauxStatut[$stKey])) {
                    $totauxStatut[$stKey] = 0.0;
                }
                $totauxStatut[$stKey] += $c['montant_ht'];
            }
            // Facturé en N = somme des échéances mensuelles (comme Excel SUM(I:Z))
            $factureEnN = round($total, 2);
            $contrat = $a['montant_contrat_ht'] !== null ? (float) $a['montant_contrat_ht'] : null;
            $encaisseN1 = (float) $a['encaisse_n1'];
            // À facturer = contrat HT − encaissé N−1 − facturé en N
            $aFacturer = $contrat !== null
                ? round($contrat - $encaisseN1 - $factureEnN, 2)
                : null;

            $lignes[] = [
                'id' => $aid,
                'reference' => $a['reference'],
                'client' => (string) $a['client'],
                'type' => (string) $a['type'],
                'montant_contrat_ht' => $contrat,
                'encaisse_n1' => $encaisseN1,
                'facture_en_n' => $factureEnN,
                'restant_a_facturer' => $aFacturer, // libellé UI : « À facturer »
                'fini' => (int) ($a['fini'] ?? 0) === 1,
                'mar' => $isMar,
                'groupe' => $groupe,
                'total_planifie' => $total,
                'cellules' => $cellules,
            ];
        }

        usort($lignes, static function (array $a, array $b): int {
            $oa = self::groupeOrdre($a['groupe']);
            $ob = self::groupeOrdre($b['groupe']);
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $ra = (string) ($a['reference'] ?? '');
            $rb = (string) ($b['reference'] ?? '');
            $c = strcasecmp($ra, $rb);
            return $c !== 0 ? $c : strcasecmp((string) $a['client'], (string) $b['client']);
        });

        $totauxSynthese = [
            'contrat' => 0.0,
            'encaisse_n1' => 0.0,
            'facture_en_n' => 0.0,
            'restant_a_facturer' => 0.0,
        ];
        foreach ($lignes as $l) {
            $totauxSynthese['contrat'] += (float) ($l['montant_contrat_ht'] ?? 0);
            $totauxSynthese['encaisse_n1'] += (float) $l['encaisse_n1'];
            $totauxSynthese['facture_en_n'] += (float) $l['facture_en_n'];
            $totauxSynthese['restant_a_facturer'] += (float) ($l['restant_a_facturer'] ?? 0);
        }

        $facturesRapp = $this->facturesPourRapprochement($exerciceId);
        $rapp = $this->rapprocherPayeFactures($lignes, $facturesRapp);
        $alertesBrutes = $rapp['alertes'];
        $alertesPaye = [];
        $alertesValidees = 0;
        foreach ($lignes as &$ligne) {
            foreach ($ligne['cellules'] as $mk => &$cell) {
                if (($cell['statut'] ?? '') !== 'paye') {
                    continue;
                }
                $key = $ligne['id'] . ':' . $mk;
                $sansFacture = isset($alertesBrutes[$key]);
                $valide = !empty($cell['ecart_ok']);
                // Une fois validé par l’utilisateur, on ne ré-alerte plus (même si le rapprochement change).
                $cell['alerte_facture'] = $sansFacture && !$valide;
                $cell['ecart_valide'] = $valide;
                if ($cell['alerte_facture']) {
                    $alertesPaye[$key] = true;
                } elseif ($valide) {
                    $alertesValidees++;
                }
            }
            unset($cell);
        }
        unset($ligne);

        return [
            'mois' => $mois,
            'lignes' => $lignes,
            'totaux_mois' => $totauxMois,
            'totaux_statut' => $totauxStatut,
            'totaux_synthese' => $totauxSynthese,
            'factures_rapprochement' => $facturesRapp,
            'nb_alertes_facture' => count($alertesPaye),
            'nb_alertes_validees' => $alertesValidees,
        ];
    }

    /**
     * Valide tous les écarts bleus encore ouverts (sans facture cohérente).
     * @return int nombre de cellules validées
     */
    public function validerRecoupementsRestants(int $exerciceId): int
    {
        $grille = $this->grille($exerciceId);
        $upd = $this->pdo->prepare(
            'UPDATE echeances_facturation SET ecart_ok = 1
             WHERE affaire_id = ? AND annee_mois = ? AND statut = \'paye\''
        );
        $n = 0;
        foreach ($grille['lignes'] as $ligne) {
            $aid = (int) $ligne['id'];
            foreach ($ligne['cellules'] as $mk => $cell) {
                if (empty($cell['alerte_facture'])) {
                    continue;
                }
                $upd->execute([$aid, (string) $mk]);
                $n += $upd->rowCount() > 0 ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * Métadonnées affichage factures : statut paiement + flag MAR.
     * Statut : paye (bleu) · facture (vert) · litige (jaune impayé). Pas de rouge.
     * MAR = badge séparé pour le ratio CA MAR.
     *
     * @return array<int, array{statut: 'paye'|'facture'|'litige', mar: bool, auto_statut: bool, auto_mar: bool}>
     */
    public function metaFactures(int $exerciceId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, reference, client, type
             FROM affaires WHERE exercice_id = ?'
        );
        $st->execute([$exerciceId]);
        $affaires = $st->fetchAll();

        $st = $this->pdo->prepare(
            'SELECT e.affaire_id, e.annee_mois, e.montant_ht, e.statut
             FROM echeances_facturation e
             INNER JOIN affaires a ON a.id = e.affaire_id
             WHERE a.exercice_id = ?'
        );
        $st->execute([$exerciceId]);
        $byAffaire = [];
        foreach ($st->fetchAll() as $e) {
            $aid = (int) $e['affaire_id'];
            $byAffaire[$aid][(string) $e['annee_mois']] = [
                'montant_ht' => (float) $e['montant_ht'],
                'statut' => (string) $e['statut'],
            ];
        }

        $lignes = [];
        $meta = [];
        foreach ($affaires as $a) {
            $aid = (int) $a['id'];
            $groupe = self::groupeAffaire((string) $a['type'], $a['reference'], false);
            $meta[$aid] = $groupe;
            $lignes[] = [
                'id' => $aid,
                'client' => (string) $a['client'],
                'groupe' => $groupe,
                'cellules' => $byAffaire[$aid] ?? [],
            ];
        }

        $factures = $this->facturesPourRapprochement($exerciceId);
        $stAll = $this->pdo->prepare(
            'SELECT id, date_facture, client, ht, numero, statut_paiement, est_mar
             FROM factures WHERE exercice_id = ?'
        );
        $stAll->execute([$exerciceId]);
        $allFac = $stAll->fetchAll();

        $rapp = $this->rapprocherPayeFactures($lignes, $factures);
        $payees = $rapp['factures_paye'];
        $groupes = $this->groupesFactures($lignes, $factures, $meta, $rapp['facture_affaires'] ?? []);
        $statutsPlan = $this->statutsFacturesDepuisPlanning($lignes, $factures, $payees);

        $out = [];
        foreach ($allFac as $r) {
            $id = (int) $r['id'];
            $ht = (float) ($r['ht'] ?? 0);
            $manuel = isset($r['statut_paiement']) && $r['statut_paiement'] !== '' && $r['statut_paiement'] !== null;
            $statutMan = $manuel ? (string) $r['statut_paiement'] : null;
            if ($statutMan !== null && !in_array($statutMan, ['paye', 'facture', 'litige'], true)) {
                $statutMan = null;
            }

            // Avoir / ligne à 0 € (ex. avoir de TVA BEGOU) = considéré payé.
            if (abs($ht) < 0.005) {
                $statut = 'paye';
                $autoStatut = true;
            } elseif ($statutMan !== null) {
                $statut = $statutMan;
                $autoStatut = false;
            } elseif (isset($statutsPlan[$id])) {
                $statut = $statutsPlan[$id];
                $autoStatut = true;
            } elseif (isset($payees[$id])) {
                $statut = 'paye';
                $autoStatut = true;
            } else {
                $statut = 'facture';
                $autoStatut = true;
            }

            $marAuto = ($groupes[$id] ?? null) === 'mar';
            if ($r['est_mar'] !== null && $r['est_mar'] !== '') {
                $mar = (int) $r['est_mar'] === 1;
                $autoMar = false;
            } else {
                $mar = $marAuto;
                $autoMar = true;
            }

            $out[$id] = [
                'statut' => $statut,
                'mar' => $mar,
                'auto_statut' => $autoStatut,
                'auto_mar' => $autoMar,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, 'paye'|'facture'|'litige'>
     */
    public function couleursFactures(int $exerciceId): array
    {
        $out = [];
        foreach ($this->metaFactures($exerciceId) as $id => $m) {
            $out[$id] = $m['statut'];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $lignes
     * @param list<array{id:int, ym:string, ht:float, client:string, numero:string}> $factures
     * @param array<int, true> $payees
     * @return array<int, 'paye'|'facture'|'litige'>
     */
    private function statutsFacturesDepuisPlanning(
        array $lignes,
        array $factures,
        array $payees
    ): array {
        $cells = [];
        foreach ($lignes as $l) {
            foreach ($l['cellules'] as $ym => $c) {
                $ht = round((float) $c['montant_ht'], 2);
                if (abs($ht) < 0.005) {
                    continue;
                }
                $cells[] = [
                    'ym' => (string) $ym,
                    'ht' => $ht,
                    'client' => (string) ($l['client'] ?? ''),
                    'statut' => (string) ($c['statut'] ?? 'a_facturer'),
                ];
            }
        }

        $out = [];
        foreach ($factures as $f) {
            $fid = (int) $f['id'];
            if (isset($payees[$fid])) {
                $out[$fid] = 'paye';
                continue;
            }
            $best = null;
            $bestScore = -1;
            foreach ($cells as $c) {
                if (abs($c['ht'] - $f['ht']) > 0.015) {
                    continue;
                }
                $diff = $this->monthDiff($f['ym'], $c['ym']);
                if ($diff === null || $diff > 1) {
                    continue;
                }
                $ov = $this->clientsOverlap($f['client'], $c['client']);
                $score = (100 - $diff * 20) + ($ov ? 40 : 0);
                if ($c['statut'] === 'paye') {
                    $score += 15;
                } elseif ($c['statut'] === 'litige') {
                    $score += 8;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $c['statut'];
                }
            }
            if ($best === null) {
                continue;
            }
            if ($best === 'paye') {
                $out[$fid] = 'paye';
            } elseif ($best === 'litige') {
                $out[$fid] = 'litige';
            } else {
                $out[$fid] = 'facture';
            }
        }
        return $out;
    }

    /**
     * Groupe planning le plus probable pour chaque facture.
    /**
     * Groupe planning le plus probable pour chaque facture.
     *
     * @param list<array<string,mixed>> $lignes
     * @param list<array{id:int, ym:string, ht:float, client:string, numero:string}> $factures
     * @param array<int, string> $meta affaire_id → groupe
     * @param array<int, list<int>> $factureAffaires facture_id → affaire_ids (déjà rapprochés payé)
     * @return array<int, string> facture_id → groupe
     */
    private function groupesFactures(
        array $lignes,
        array $factures,
        array $meta,
        array $factureAffaires
    ): array {
        $out = [];
        foreach ($factureAffaires as $fid => $aids) {
            $g = $this->groupePrioritaire($aids, $meta);
            if ($g !== null) {
                $out[(int) $fid] = $g;
            }
        }

        $cells = [];
        foreach ($lignes as $l) {
            $aid = (int) $l['id'];
            foreach ($l['cellules'] as $ym => $c) {
                $ht = round((float) $c['montant_ht'], 2);
                if (abs($ht) < 0.005) {
                    continue;
                }
                $cells[] = [
                    'affaire_id' => $aid,
                    'ym' => (string) $ym,
                    'ht' => $ht,
                    'client' => (string) ($l['client'] ?? ''),
                    'groupe' => (string) ($l['groupe'] ?? 'contrat'),
                ];
            }
        }

        foreach ($factures as $f) {
            $fid = (int) $f['id'];
            if (isset($out[$fid])) {
                continue;
            }
            $best = null;
            $bestScore = -1;
            foreach ($cells as $c) {
                if (abs($c['ht'] - $f['ht']) > 0.015) {
                    continue;
                }
                $diff = $this->monthDiff($f['ym'], $c['ym']);
                if ($diff === null || $diff > 1) {
                    continue;
                }
                $ov = $this->clientsOverlap($f['client'], $c['client']);
                $score = (100 - $diff * 20) + ($ov ? 40 : 0);
                if ($c['groupe'] === 'mar') {
                    $score += 5;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $c['groupe'];
                }
            }
            if ($best !== null) {
                $out[$fid] = $best;
                continue;
            }
            // Repli : client uniquement s’il ne matche que des MAR (ou que des non-MAR)
            $marHit = false;
            $autreHit = false;
            foreach ($lignes as $l) {
                if (!$this->clientsOverlap($f['client'], (string) ($l['client'] ?? ''))) {
                    continue;
                }
                if (($l['groupe'] ?? '') === 'mar') {
                    $marHit = true;
                } else {
                    $autreHit = true;
                }
            }
            if ($marHit && !$autreHit) {
                $out[$fid] = 'mar';
            } elseif ($autreHit && !$marHit) {
                $out[$fid] = 'contrat';
            }
        }
        return $out;
    }

    /**
     * @param list<int> $aids
     * @param array<int, string> $meta
     */
    private function groupePrioritaire(array $aids, array $meta): ?string
    {
        if ($aids === []) {
            return null;
        }
        foreach ($aids as $aid) {
            if (($meta[(int) $aid] ?? '') === 'mar') {
                return 'mar';
            }
        }
        $aid0 = (int) $aids[0];
        return $meta[$aid0] ?? 'contrat';
    }

    /**
     * Factures de l’exercice pour rapprochement avec cellules bleues (payé).
     *
     * @return list<array{id:int, ym:string, ht:float, client:string, numero:string}>
     */
    private function facturesPourRapprochement(int $exerciceId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, date_facture, client, ht, numero
             FROM factures
             WHERE exercice_id = ? AND ABS(COALESCE(ht, 0)) > 0.001
             ORDER BY date_facture, id'
        );
        $st->execute([$exerciceId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $date = (string) ($r['date_facture'] ?? '');
            $ym = preg_replace('/\D/', '', substr($date, 0, 7));
            if ($ym === null || strlen($ym) !== 6) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'ym' => $ym,
                'ht' => round((float) $r['ht'], 2),
                'client' => (string) ($r['client'] ?? ''),
                'numero' => (string) ($r['numero'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $lignes
     * @param list<array{id:int, ym:string, ht:float, client:string, numero:string}> $factures
     * @return array{
     *   alertes: array<string, true>,
     *   factures_paye: array<int, true>,
     *   facture_affaires: array<int, list<int>>
     * }
     */
    private function rapprocherPayeFactures(array $lignes, array $factures): array
    {
        $cells = [];
        foreach ($lignes as $l) {
            $aid = (int) $l['id'];
            foreach ($l['cellules'] as $ym => $c) {
                if (($c['statut'] ?? '') !== 'paye') {
                    continue;
                }
                $ht = round((float) $c['montant_ht'], 2);
                if (abs($ht) < 0.005) {
                    continue;
                }
                $cells[] = [
                    'key' => $aid . ':' . $ym,
                    'affaire_id' => $aid,
                    'ym' => (string) $ym,
                    'ht' => $ht,
                    'client' => (string) ($l['client'] ?? ''),
                ];
            }
        }

        $matched = [];
        $usedFact = [];
        $factureAffaires = [];
        if ($cells !== []) {
            $this->assignPayeUnAUn($cells, $factures, $matched, $usedFact, $factureAffaires, 0, true);
            $this->assignPayeUnAUn($cells, $factures, $matched, $usedFact, $factureAffaires, 0, false);
            $this->assignPayeSousEnsembles($cells, $factures, $matched, $usedFact, $factureAffaires);
            $this->assignPayeUnAUn($cells, $factures, $matched, $usedFact, $factureAffaires, 1, true);
            $this->assignPayeUnAUn($cells, $factures, $matched, $usedFact, $factureAffaires, 1, false);
        }

        $alertes = [];
        foreach ($cells as $c) {
            if (!isset($matched[$c['key']])) {
                $alertes[$c['key']] = true;
            }
        }
        return [
            'alertes' => $alertes,
            'factures_paye' => $usedFact,
            'facture_affaires' => $factureAffaires,
        ];
    }

    /**
     * @param list<array{key:string, affaire_id?:int, ym:string, ht:float, client:string}> $cells
     * @param list<array{id:int, ym:string, ht:float, client:string, numero:string}> $factures
     * @param array<string, true> $matched
     * @param array<int, true> $usedFact
     * @param array<int, list<int>> $factureAffaires
     */
    private function assignPayeUnAUn(
        array $cells,
        array $factures,
        array &$matched,
        array &$usedFact,
        array &$factureAffaires,
        int $maxMonthDiff,
        bool $requireClient
    ): void {
        foreach ($cells as $c) {
            if (isset($matched[$c['key']])) {
                continue;
            }
            $bestId = null;
            $bestScore = -1;
            foreach ($factures as $f) {
                $fid = (int) $f['id'];
                if (isset($usedFact[$fid])) {
                    continue;
                }
                if (abs($c['ht'] - $f['ht']) > 0.015) {
                    continue;
                }
                $diff = $this->monthDiff($c['ym'], $f['ym']);
                if ($diff === null || $diff > $maxMonthDiff) {
                    continue;
                }
                $overlap = $this->clientsOverlap($c['client'], $f['client']);
                if ($requireClient && !$overlap) {
                    continue;
                }
                $score = (100 - $diff * 20) + ($overlap ? 30 : 0);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestId = $fid;
                }
            }
            if ($bestId !== null) {
                $matched[$c['key']] = true;
                $usedFact[$bestId] = true;
                $aid = (int) ($c['affaire_id'] ?? 0);
                if ($aid > 0) {
                    $factureAffaires[$bestId][] = $aid;
                }
            }
        }
    }

    /**
     * @param list<array{key:string, affaire_id?:int, ym:string, ht:float, client:string}> $cells
     * @param list<array{id:int, ym:string, ht:float, client:string, numero:string}> $factures
     * @param array<string, true> $matched
     * @param array<int, true> $usedFact
     * @param array<int, list<int>> $factureAffaires
     */
    private function assignPayeSousEnsembles(
        array $cells,
        array $factures,
        array &$matched,
        array &$usedFact,
        array &$factureAffaires
    ): void {
        $byMonth = [];
        foreach ($cells as $i => $c) {
            if (isset($matched[$c['key']])) {
                continue;
            }
            $byMonth[$c['ym']][] = $i;
        }
        foreach ($factures as $f) {
            $fid = (int) $f['id'];
            if (isset($usedFact[$fid])) {
                continue;
            }
            $ym = $f['ym'];
            if (!isset($byMonth[$ym])) {
                continue;
            }
            $idxs = [];
            foreach ($byMonth[$ym] as $i) {
                if (!isset($matched[$cells[$i]['key']])) {
                    $idxs[] = $i;
                }
            }
            if (count($idxs) < 2) {
                continue;
            }
            $combo = $this->findSubsetSum($cells, $idxs, (float) $f['ht']);
            if ($combo === null) {
                continue;
            }
            $usedFact[$fid] = true;
            foreach ($combo as $i) {
                $matched[$cells[$i]['key']] = true;
                $aid = (int) ($cells[$i]['affaire_id'] ?? 0);
                if ($aid > 0) {
                    $factureAffaires[$fid][] = $aid;
                }
            }
        }
    }

    /**
     * Sous-ensemble (2–6 cellules) dont la somme ≈ cible.
     *
     * @param list<array{key:string, ym:string, ht:float, client:string}> $cells
     * @param list<int> $idxs
     * @return list<int>|null
     */
    private function findSubsetSum(array $cells, array $idxs, float $target): ?array
    {
        $n = count($idxs);
        if ($n < 2) {
            return null;
        }
        $maxR = min(6, $n);
        $found = null;
        $walk = function (int $start, array $picked, float $sum) use (
            &$walk,
            &$found,
            $cells,
            $idxs,
            $n,
            $maxR,
            $target
        ): void {
            if ($found !== null) {
                return;
            }
            $pc = count($picked);
            if ($pc >= 2 && abs($sum - $target) <= 0.02) {
                $found = $picked;
                return;
            }
            if ($pc >= $maxR) {
                return;
            }
            for ($j = $start; $j < $n; $j++) {
                $i = $idxs[$j];
                $ht = $cells[$i]['ht'];
                // Élagage : somme trop grande (même signe dominant)
                if ($pc > 0 && $target >= 0 && $sum + $ht > $target + 0.02 && $ht > 0) {
                    continue;
                }
                $picked[] = $i;
                $walk($j + 1, $picked, $sum + $ht);
                array_pop($picked);
                if ($found !== null) {
                    return;
                }
            }
        };
        $walk(0, [], 0.0);
        return $found;
    }

    private function monthDiff(string $a, string $b): ?int
    {
        if (strlen($a) !== 6 || strlen($b) !== 6) {
            return null;
        }
        $ya = (int) substr($a, 0, 4);
        $ma = (int) substr($a, 4, 2);
        $yb = (int) substr($b, 0, 4);
        $mb = (int) substr($b, 4, 2);
        if ($ma < 1 || $ma > 12 || $mb < 1 || $mb > 12) {
            return null;
        }
        return abs(($ya * 12 + $ma) - ($yb * 12 + $mb));
    }

    private function clientsOverlap(string $a, string $b): bool
    {
        $ta = $this->clientTokens($a);
        $tb = $this->clientTokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }
        return array_intersect($ta, $tb) !== [];
    }

    /** @return list<string> */
    private function clientTokens(string $s): array
    {
        $raw = $s;
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        if (!is_string($s) || $s === '') {
            $s = $raw;
        }
        $s = strtoupper($s);
        $parts = preg_split('/[^A-Z0-9]+/', $s) ?: [];
        $out = [];
        foreach ($parts as $t) {
            if (strlen($t) >= 3) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Groupe d’affichage : Archi · MAR · STRIPE · PV · divers. */
    public static function groupeAffaire(string $type, mixed $reference, bool $isMar = false): string
    {
        $ref = strtoupper(trim((string) $reference));
        if ($type === 'stripe' || str_starts_with($ref, 'STRIPE')) {
            return 'stripe';
        }
        if ($type === 'pv' || str_contains($ref, 'PV')) {
            return 'pv';
        }
        if ($type === 'mission' || $isMar || (isset($ref[0]) && $ref[0] === 'M' && !str_starts_with($ref, 'MISE'))) {
            return 'mar';
        }
        if ($type === 'autre' || str_starts_with($ref, 'DIVERS')) {
            return 'divers';
        }
        return 'contrat';
    }

    public static function groupeOrdre(string $groupe): int
    {
        return match ($groupe) {
            'contrat' => 1,
            'mar' => 2,
            'stripe' => 3,
            'pv' => 4,
            'divers' => 5,
            default => 9,
        };
    }

    public static function groupeLabel(string $groupe): string
    {
        return match ($groupe) {
            'contrat' => 'Archi',
            'mar' => 'MAR',
            'stripe' => 'STRIPE',
            'pv' => 'PV',
            'divers' => 'Divers',
            default => $groupe,
        };
    }

    /** @return list<string> YYYYMM */
    private function monthsBetween(string $debut, string $fin): array
    {
        $out = [];
        $d = date_create(substr($debut, 0, 7) . '-01');
        $end = date_create(substr($fin, 0, 7) . '-01');
        if (!$d || !$end) {
            return $out;
        }
        while ($d <= $end) {
            $out[] = $d->format('Ym');
            $d->modify('+1 month');
        }
        return $out;
    }

    private function labelMois(string $yyyymm): string
    {
        if (strlen($yyyymm) !== 6) {
            return $yyyymm;
        }
        $mm = substr($yyyymm, 4, 2);
        $yy = substr($yyyymm, 2, 2);
        return (self::MOIS_FR[$mm] ?? $mm) . ' ' . $yy;
    }

    public static function statutLabel(string $statut): string
    {
        return match ($statut) {
            'a_facturer' => 'À facturer',
            'facture' => 'Facturé',
            'litige' => 'Litige client',
            'paye' => 'Payé',
            default => $statut,
        };
    }

    public static function detectType(string $reference): string
    {
        $s = trim($reference);
        $su = strtoupper($s);
        if (str_starts_with($su, 'STRIPE')) {
            return 'stripe';
        }
        if (str_contains($su, 'PV')) {
            return 'pv';
        }
        if (str_starts_with($su, 'DIVERS')) {
            return 'autre';
        }
        if (isset($su[0]) && $su[0] === 'M' && !str_starts_with($su, 'MISE')) {
            return 'mission';
        }
        if (str_starts_with($su, 'C') || preg_match('/^\d{4}/', $s) === 1) {
            return 'contrat';
        }
        return 'autre';
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'contrat' => 'Archi',
            'mission' => 'MAR',
            'stripe' => 'Stripe',
            'pv' => 'PV',
            default => 'Autre',
        };
    }
}
