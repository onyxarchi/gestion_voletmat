<?php
declare(strict_types=1);

namespace Voletmat;

use DateTimeImmutable;
use PDO;

/**
 * Prévisionnel : budget (feuille Excel) vs réel banque, ordre + couleurs Excel.
 * Les lignes « mensuel » sont exprimées sur toute la durée de l’exercice
 * (ex. 18 mois N5) ; les autres restent un total de période.
 */
final class PrevisionnelService
{
    /** Codes dont le budget = max(débit banque) × nb mois exercice. */
    public const BUDGET_FROM_BANQUE_MAX = [
        'PER' => ['tva' => false],      // GALYA / GGVIE
        'PREV' => ['tva' => false],     // SWISSLIFE
        'PJ' => ['tva' => false],       // GPJ / GAN
        // Compagnie Fiduciaire : forfait mensuel (pas le max banque, qui peut être un double prélèvement)
        'COMPTA' => ['tva' => true, 'fixe_mensuel' => 205.0],
        'NET' => ['tva' => true, 'fixe_mensuel' => 23.99], // FREE Telecom (hors pic ponctuel)
        'TEL' => ['tva' => true],       // Bouygues
        'BANQUE' => ['tva' => true],    // frais / factures CIC
    ];

    /** Codes hors TVA (salaires, Madelin, impôts, etc.). Les autres = TVA 20 %. */
    public const SANS_TVA = [
        'REM', 'DIVIDENDES', 'CCA', 'IK', 'CESU', 'FORMATION',
        'URSSAF', 'PREV', 'PER', 'PJ', 'SMABTP', 'OA', 'ASS BUREAU',
        'CFE', 'IS', 'JURIDIQUE', 'TVA', 'ASSOS', 'EPARGNE', 'ADMIN', 'BUREAU',
    ];

    public const TAUX_TVA = 0.20;

    public static function avecTva(string $code): bool
    {
        return !in_array(strtoupper(trim($code)), self::SANS_TVA, true);
    }

    /** @return array{ht: float, tva: float, ttc: float} */
    public static function montantsDepuisHt(string $code, float $ht): array
    {
        $ht = round($ht, 2);
        if (!self::avecTva($code) || abs($ht) < 0.005) {
            return ['ht' => $ht, 'tva' => 0.0, 'ttc' => $ht];
        }
        $tva = round($ht * self::TAUX_TVA, 2);
        return ['ht' => $ht, 'tva' => $tva, 'ttc' => round($ht + $tva, 2)];
    }

    /** @return array{ht: float, tva: float, ttc: float} */
    public static function montantsDepuisTtc(string $code, float $ttc): array
    {
        $ttc = round($ttc, 2);
        if (!self::avecTva($code) || abs($ttc) < 0.005) {
            return ['ht' => $ttc, 'tva' => 0.0, 'ttc' => $ttc];
        }
        $ht = round($ttc / (1.0 + self::TAUX_TVA), 2);
        $tva = round($ttc - $ht, 2);
        return ['ht' => $ht, 'tva' => $tva, 'ttc' => $ttc];
    }

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   nb_mois:int,
     *   lignes: list<array{
     *     code:string, libelle:string, famille:string, mensuel:bool,
     *     budget_ht:float, budget_tva:float, budget_ttc:float,
     *     reel:float, ecart:float, pct:?float,
     *     from_banque:?array{max_mensuel:float, nb_mois:int}
     *   }>,
     *   totaux: array{budget_ht:float, budget_tva:float, budget_ttc:float, reel:float, ecart:float},
     *   synthese: list<array{label:string, famille:string, budget_ht:float, reel_ttc:float}>,
     *   marge_pct:float, marge_net:float, objectif_ca_ht:float
     * }
     */
    public function grille(int $exerciceId): array
    {
        $nbMois = $this->nbMoisExercice($exerciceId);
        $this->transposerBudgetsMensuels($exerciceId, $nbMois);
        $fromBanque = $this->syncBudgetsFromBanqueMax($exerciceId, $nbMois);
        $this->normalizeBudgetsTva($exerciceId);

        $st = $this->pdo->prepare(
            'SELECT b.categorie_code, b.montant_ht, b.montant_tva, b.montant_ttc,
                    c.libelle
             FROM budgets b
             LEFT JOIN categories c ON c.code = b.categorie_code
             WHERE b.exercice_id = ?'
        );
        $st->execute([$exerciceId]);
        $byCode = [];
        foreach ($st->fetchAll() as $b) {
            $byCode[(string) $b['categorie_code']] = $b;
        }

        $st = $this->pdo->prepare(
            'SELECT categorie_code,
                    COALESCE(SUM(ABS(COALESCE(debit, 0))), 0) AS debits,
                    COALESCE(SUM(ABS(COALESCE(credit, 0))), 0) AS credits
             FROM operations_bancaires
             WHERE exercice_id = ?
             GROUP BY categorie_code'
        );
        $st->execute([$exerciceId]);
        $reelByCat = [];
        foreach ($st->fetchAll() as $r) {
            $code = (string) ($r['categorie_code'] ?? '');
            if ($code === '' || $code === 'VENTE' || $code === 'AVOIR') {
                continue;
            }
            // RÉEL = |débits − crédits| (= |total ligne analytique|, toujours positif)
            $reelByCat[$code] = abs((float) $r['debits'] - (float) $r['credits']);
        }

        $lignes = [];
        foreach (TriLignesExcel::LIGNES as $code => $meta) {
            $b = $byCode[$code] ?? null;
            $budgetHt = $b ? round((float) $b['montant_ht'], 2) : 0.0;
            $budgetTtc = $b ? round((float) $b['montant_ttc'], 2) : 0.0;
            $budgetTva = $b ? round((float) $b['montant_tva'], 2) : round($budgetTtc - $budgetHt, 2);
            if ($budgetTva < 0) {
                $budgetTva = 0.0;
            }
            $libelle = $b
                ? (string) ($b['libelle'] ?? $code)
                : $this->libelleCategorie($code);
            $reel = round((float) ($reelByCat[$code] ?? 0.0), 2);
            $ecart = round($budgetTtc - $reel, 2);
            $pct = abs($budgetTtc) > 0.005 ? ($reel / $budgetTtc) : null;
            $lignes[] = [
                'code' => $code,
                'libelle' => $libelle,
                'famille' => $meta['famille'],
                'mensuel' => !empty($meta['mensuel']),
                'avec_tva' => self::avecTva($code),
                'budget_ht' => $budgetHt,
                'budget_tva' => $budgetTva,
                'budget_ttc' => $budgetTtc,
                'reel' => $reel,
                'ecart' => $ecart,
                'pct' => $pct,
                'from_banque' => $fromBanque[$code] ?? null,
            ];
        }

        // TRI hors feuille N5 (ex. codes N4 ASS/CLE/FBQ) : ignorés ici.

        $totHt = $totTva = $totTtc = $totReel = 0.0;
        $byCodeLigne = [];
        foreach ($lignes as $l) {
            $totHt += $l['budget_ht'];
            $totTva += $l['budget_tva'];
            $totTtc += $l['budget_ttc'];
            $totReel += $l['reel'];
            $byCodeLigne[$l['code']] = $l;
        }

        $sumHt = static function (array $codes) use ($byCodeLigne): float {
            $s = 0.0;
            foreach ($codes as $c) {
                $s += (float) ($byCodeLigne[$c]['budget_ht'] ?? 0);
            }
            return round($s, 2);
        };
        $sumReel = static function (array $codes) use ($byCodeLigne): float {
            $s = 0.0;
            foreach ($codes as $c) {
                $s += (float) ($byCodeLigne[$c]['reel'] ?? 0);
            }
            return round($s, 2);
        };

        // Groupes Excel lignes 43–47
        $fixesA = ['URSSAF', 'PREV', 'PER', 'PJ', 'SMABTP', 'OA', 'CFE', 'COMPTA', 'JURIDIQUE', 'TVA', 'RECOUV', 'IS'];
        $salairesB = ['REM', 'DIVIDENDES', 'CCA'];
        $variablesC = [
            'DIVERS', 'SST', 'ADMIN', 'DEPLACEMENT', 'TEL', 'POSTE', 'RESTO', 'SITE',
            'ARCHICAD', 'LOGICIEL', 'INFORMATIQUE', 'FOURN', 'ASSOS', 'FORMATION', 'CESU', 'IK',
        ];
        $bureauxD = ['BUREAU', 'NET', 'ASS BUREAU'];

        $synthese = [
            [
                'label' => 'A - charges fixes',
                'famille' => 'cyan',
                'budget_ht' => $sumHt($fixesA),
                'reel_ttc' => $sumReel($fixesA),
            ],
            [
                'label' => 'B - Salaires',
                'famille' => 'salaire',
                'budget_ht' => $sumHt($salairesB),
                'reel_ttc' => $sumReel($salairesB),
            ],
            [
                'label' => 'Charges Fixes (A+B)',
                'famille' => 'neutre',
                'budget_ht' => round($sumHt($fixesA) + $sumHt($salairesB), 2),
                'reel_ttc' => round($sumReel($fixesA) + $sumReel($salairesB), 2),
            ],
            [
                'label' => 'C - Charges variables',
                'famille' => 'vert_bureau',
                'budget_ht' => $sumHt($variablesC),
                'reel_ttc' => $sumReel($variablesC),
            ],
            [
                'label' => 'D - Bureaux',
                'famille' => 'violet',
                'budget_ht' => $sumHt($bureauxD),
                'reel_ttc' => $sumReel($bureauxD),
            ],
        ];
        $totalDep = round(
            $synthese[2]['budget_ht'] + $synthese[3]['budget_ht'] + $synthese[4]['budget_ht'],
            2
        );
        $totalDepReel = round(
            $synthese[2]['reel_ttc'] + $synthese[3]['reel_ttc'] + $synthese[4]['reel_ttc'],
            2
        );
        $synthese[] = [
            'label' => 'Total dépenses (A+B+C+D)',
            'famille' => 'total',
            'budget_ht' => $totalDep,
            'reel_ttc' => $totalDepReel,
        ];

        $stM = $this->pdo->prepare('SELECT COALESCE(marge_pct, 0) FROM exercices WHERE id = ?');
        $stM->execute([$exerciceId]);
        $margePct = round((float) $stM->fetchColumn(), 4);
        $taux = $margePct / 100.0;
        $objectif = round($totalDep * (1.0 + $taux), 2);
        $margeNet = round($totalDep * $taux, 2);

        return [
            'nb_mois' => $nbMois,
            'lignes' => $lignes,
            'totaux' => [
                'budget_ht' => round($totHt, 2),
                'budget_tva' => round($totTva, 2),
                'budget_ttc' => round($totTtc, 2),
                'reel' => round($totReel, 2),
                'ecart' => round($totTtc - $totReel, 2),
            ],
            'synthese' => $synthese,
            'marge_pct' => $margePct,
            'marge_net' => $margeNet,
            'objectif_ca_ht' => $objectif,
        ];
    }

    /**
     * Budget = prélèvement banque le plus élevé × durée de l’exercice.
     * Montants banque = TTC ; Madelin (PER/PREV/PJ) sans TVA, les autres en 20 %.
     *
     * @return array<string, array{max_mensuel:float, nb_mois:int}>
     */
    private function syncBudgetsFromBanqueMax(int $exerciceId, int $nbMois): array
    {
        $stMax = $this->pdo->prepare(
            'SELECT MAX(ABS(debit)) FROM operations_bancaires
             WHERE exercice_id = ?
               AND categorie_code = ?
               AND debit IS NOT NULL
               AND ABS(debit) >= 0.005'
        );
        // Frais bancaires récurrents (facture CIC) — hors frais CB ponctuels / pics anormaux
        $stMaxBanque = $this->pdo->prepare(
            "SELECT MAX(ABS(debit)) FROM operations_bancaires
             WHERE exercice_id = ?
               AND categorie_code = 'BANQUE'
               AND debit IS NOT NULL
               AND ABS(debit) >= 0.005
               AND ABS(debit) <= 100
               AND libelle LIKE '%FACT SGT%'"
        );
        $upsert = $this->pdo->prepare(
            'INSERT INTO budgets (exercice_id, categorie_code, montant_ht, montant_tva, montant_ttc)
             VALUES (?,?,?,?,?)
             ON CONFLICT(exercice_id, categorie_code) DO UPDATE SET
               montant_ht = excluded.montant_ht,
               montant_tva = excluded.montant_tva,
               montant_ttc = excluded.montant_ttc'
        );
        $out = [];
        foreach (self::BUDGET_FROM_BANQUE_MAX as $code => $opt) {
            if (isset($opt['fixe_mensuel'])) {
                $max = (float) $opt['fixe_mensuel'];
            } elseif ($code === 'BANQUE') {
                $stMaxBanque->execute([$exerciceId]);
                $max = (float) ($stMaxBanque->fetchColumn() ?: 0);
                if ($max < 0.005) {
                    $stMax->execute([$exerciceId, $code]);
                    $max = (float) ($stMax->fetchColumn() ?: 0);
                }
            } else {
                $stMax->execute([$exerciceId, $code]);
                $max = (float) ($stMax->fetchColumn() ?: 0);
            }
            if ($max < 0.005) {
                continue;
            }
            $ttc = round($max * $nbMois, 2);
            if (!empty($opt['tva'])) {
                $parts = self::montantsDepuisTtc($code, $ttc);
                $ht = $parts['ht'];
                $tva = $parts['tva'];
                $ttc = $parts['ttc'];
            } else {
                $ht = $ttc;
                $tva = 0.0;
            }
            $upsert->execute([$exerciceId, $code, $ht, $tva, $ttc]);
            $out[$code] = [
                'max_mensuel' => round($max, 2),
                'nb_mois' => $nbMois,
                'fixe' => isset($opt['fixe_mensuel']),
            ];
        }
        return $out;
    }

    /**
     * Recalcule TVA / TTC depuis le HT : 20 % si le code est assujetti, sinon TVA = 0.
     * Corrige les budgets saisis à la main avec un taux incorrect.
     */
    private function normalizeBudgetsTva(int $exerciceId): void
    {
        $st = $this->pdo->prepare(
            'SELECT categorie_code, montant_ht, montant_tva, montant_ttc
             FROM budgets WHERE exercice_id = ?'
        );
        $st->execute([$exerciceId]);
        $upd = $this->pdo->prepare(
            'UPDATE budgets
             SET montant_ht = ?, montant_tva = ?, montant_ttc = ?
             WHERE exercice_id = ? AND categorie_code = ?'
        );
        foreach ($st->fetchAll() as $row) {
            $code = (string) $row['categorie_code'];
            $ht = round((float) $row['montant_ht'], 2);
            $tva = round((float) $row['montant_tva'], 2);
            $ttc = round((float) $row['montant_ttc'], 2);
            $parts = self::montantsDepuisHt($code, $ht);
            if (
                abs($parts['ht'] - $ht) > 0.005
                || abs($parts['tva'] - $tva) > 0.005
                || abs($parts['ttc'] - $ttc) > 0.005
            ) {
                $upd->execute([
                    $parts['ht'],
                    $parts['tva'],
                    $parts['ttc'],
                    $exerciceId,
                    $code,
                ]);
            }
        }
    }

    public function nbMoisExercice(int $exerciceId): int
    {
        $st = $this->pdo->prepare('SELECT date_debut, date_fin FROM exercices WHERE id = ?');
        $st->execute([$exerciceId]);
        $row = $st->fetch();
        if (!$row) {
            return 12;
        }
        $months = $this->monthsBetween((string) $row['date_debut'], (string) $row['date_fin']);
        return max(1, count($months));
    }

    /**
     * Si les budgets mensuels sont encore exprimés sur une autre base (souvent 12 mois),
     * les ramène à la durée réelle de l’exercice (ex. ×18/12 pour N5).
     * Les codes recalculés depuis la banque sont exclus (réécrits juste après).
     */
    private function transposerBudgetsMensuels(int $exerciceId, int $nbMois): void
    {
        $exCols = array_column($this->pdo->query('PRAGMA table_info(exercices)')->fetchAll(), 'name');
        if (!in_array('previ_mois', $exCols, true)) {
            return; // migration pas encore passée
        }
        $st = $this->pdo->prepare('SELECT COALESCE(previ_mois, 12) FROM exercices WHERE id = ?');
        $st->execute([$exerciceId]);
        $base = (int) $st->fetchColumn();
        if ($base < 1) {
            $base = 12;
        }
        if ($base === $nbMois) {
            return;
        }
        $factor = $nbMois / $base;
        $upd = $this->pdo->prepare(
            'UPDATE budgets
             SET montant_ht = ROUND(montant_ht * ?, 2),
                 montant_tva = ROUND(montant_tva * ?, 2),
                 montant_ttc = ROUND(montant_ttc * ?, 2)
             WHERE exercice_id = ? AND categorie_code = ?'
        );
        foreach (TriLignesExcel::LIGNES as $code => $meta) {
            if (empty($meta['mensuel'])) {
                continue;
            }
            if (isset(self::BUDGET_FROM_BANQUE_MAX[$code])) {
                continue;
            }
            $upd->execute([$factor, $factor, $factor, $exerciceId, $code]);
        }
        $this->pdo->prepare('UPDATE exercices SET previ_mois = ? WHERE id = ?')
            ->execute([$nbMois, $exerciceId]);
    }

    /** @return list<string> Ym */
    private function monthsBetween(string $debut, string $fin): array
    {
        try {
            $d = new DateTimeImmutable(substr($debut, 0, 10));
            $end = new DateTimeImmutable(substr($fin, 0, 10));
        } catch (\Exception) {
            return [];
        }
        $d = $d->modify('first day of this month');
        $end = $end->modify('first day of this month');
        $out = [];
        while ($d <= $end) {
            $out[] = $d->format('Ym');
            $d = $d->modify('+1 month');
        }
        return $out;
    }

    private function libelleCategorie(string $code): string
    {
        $st = $this->pdo->prepare('SELECT libelle FROM categories WHERE code = ?');
        $st->execute([$code]);
        $lib = $st->fetchColumn();
        return is_string($lib) && $lib !== '' ? $lib : $code;
    }
}
