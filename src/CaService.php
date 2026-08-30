<?php
declare(strict_types=1);

namespace Voletmat;

use PDO;

/**
 * Données de l’onglet Excel « CA » (PROGRESSION CA).
 *
 * Règles d’exercice Vol&Mat :
 * - jusqu’à N4 : exercices type juil. → juin (12 mois) ;
 * - N5 : exercice long juil. 2025 → déc. 2026 (18 mois) ;
 * - à partir du 1er janvier 2027 : exercices = années civiles.
 *
 * Historique 2021–2024 : montants figés du classeur N5.
 * Périodes ouvertes : calcul depuis les factures (HT).
 */
final class CaService
{
    /** Premier jour de l’exercice long N5. */
    public const N5_DEBUT = '2025-07-01';

    /** Dernier jour de l’exercice long N5. */
    public const N5_FIN = '2026-12-31';

    /** Passage aux années civiles. */
    public const CIVILE_DEBUT = '2027-01-01';

    /**
     * Semestres Excel N5 (feuille CA) — ne pas inventer d’autres montants.
     * @var array<int, array{janv_juin: float, juil_dec: float}>
     */
    private const EXCEL_SEMESTRES = [
        2021 => ['janv_juin' => 0.0, 'juil_dec' => 24973.0],
        2022 => ['janv_juin' => 39005.0, 'juil_dec' => 26238.0],
        2023 => ['janv_juin' => 47382.0, 'juil_dec' => 28314.0],
        2024 => ['janv_juin' => 44011.0, 'juil_dec' => 44641.0],
        2025 => ['janv_juin' => 48980.0, 'juil_dec' => null],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   annees: list<array{annee:int, janv_juin:float, juil_dec:float, annee_totale:float}>,
     *   progression: list<array{label:string, ca_ht:float, evolution:?float, kind:string, debut:string, fin:string}>,
     *   mensuel: list<array{mois:string, label:string, ca_ht:float}>
     * }
     */
    public function dashboard(?int $exerciceId): array
    {
        $fromFactures = $this->semestresFromFactures();
        $annees = $this->mergeAnnees($fromFactures);
        $progression = $this->buildProgression($annees);
        $mensuel = $exerciceId ? $this->mensuelExercice($exerciceId) : [];
        $recap = $exerciceId ? $this->recapExercice($exerciceId, $annees) : null;

        return [
            'annees' => $annees,
            'progression' => $progression,
            'mensuel' => $mensuel,
            'recap' => $recap,
        ];
    }

    /**
     * Bloc Excel « exercice en cours » + « RÉCAPITULATIF » (CA / MAR).
     *
     * @param list<array{annee:int, janv_juin:float, juil_dec:float, annee_totale:float}> $annees
     * @return array{
     *   declarer: list<array{annee:int, ca:float}>,
     *   impayes: float,
     *   ca_encaisse: float,
     *   lignes: list<array{annee:int, ca_ht:float, ca_mar:float, pct_mar:?float}>
     * }
     */
    public function recapExercice(int $exerciceId, array $annees = []): array
    {
        $st = $this->pdo->prepare('SELECT date_debut, date_fin FROM exercices WHERE id = ?');
        $st->execute([$exerciceId]);
        $ex = $st->fetch();
        if (!$ex) {
            return ['declarer' => [], 'impayes' => 0.0, 'ca_encaisse' => 0.0, 'lignes' => []];
        }
        $debut = (string) $ex['date_debut'];
        $fin = (string) $ex['date_fin'];
        $y0 = (int) substr($debut, 0, 4);
        $y1 = (int) substr($fin, 0, 4);

        $declarer = [];
        for ($y = $y0; $y <= $y1; $y++) {
            $d = max($debut, sprintf('%d-01-01', $y));
            $f = min($fin, sprintf('%d-12-31', $y));
            $declarer[] = [
                'annee' => $y,
                'ca' => $this->caHtBetween($d, $f),
            ];
        }

        $caEncaisse = $this->caHtBetween($debut, $fin);
        $impayes = $this->caImpayesExercice($exerciceId);
        $marParAnnee = $this->caMarParAnneeCivile($exerciceId);

        if ($annees === []) {
            $annees = $this->mergeAnnees($this->semestresFromFactures());
        }
        $byYear = [];
        foreach ($annees as $r) {
            $byYear[(int) $r['annee']] = $r;
        }

        $lignes = [];
        for ($y = $y0; $y <= $y1; $y++) {
            if (isset($byYear[$y])) {
                $caHt = (float) $byYear[$y]['annee_totale'];
            } else {
                $caHt = $this->caHtBetween(sprintf('%d-01-01', $y), sprintf('%d-12-31', $y));
            }
            $caMar = (float) ($marParAnnee[$y] ?? 0.0);
            $pct = $caHt > 0.005 ? ($caMar / $caHt) : null;
            $lignes[] = [
                'annee' => $y,
                'ca_ht' => round($caHt, 2),
                'ca_mar' => round($caMar, 2),
                'pct_mar' => $pct,
            ];
        }

        return [
            'declarer' => $declarer,
            'impayes' => round($impayes, 2),
            'ca_encaisse' => round($caEncaisse, 2),
            'lignes' => $lignes,
        ];
    }

    /** Factures en litige (jaune planning) — hors payé / pas encore payé. */
    private function caImpayesExercice(int $exerciceId): float
    {
        $couleurs = (new PlanningService($this->pdo))->couleursFactures($exerciceId);
        $st = $this->pdo->prepare('SELECT id, ht FROM factures WHERE exercice_id = ?');
        $st->execute([$exerciceId]);
        $sum = 0.0;
        foreach ($st->fetchAll() as $r) {
            $id = (int) $r['id'];
            if (($couleurs[$id] ?? '') === 'litige') {
                $sum += (float) $r['ht'];
            }
        }
        return $sum;
    }

    /**
     * CA MAR par année civile : factures marquées MAR (planning ou forcé).
     *
     * @return array<int, float>
     */
    private function caMarParAnneeCivile(int $exerciceId): array
    {
        $meta = (new PlanningService($this->pdo))->metaFactures($exerciceId);
        $st = $this->pdo->prepare(
            'SELECT id, date_facture, ht FROM factures WHERE exercice_id = ?'
        );
        $st->execute([$exerciceId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $id = (int) $r['id'];
            if (empty($meta[$id]['mar'])) {
                continue;
            }
            $y = (int) substr((string) $r['date_facture'], 0, 4);
            if ($y < 2000) {
                continue;
            }
            $out[$y] = ($out[$y] ?? 0.0) + (float) $r['ht'];
        }
        return $out;
    }

    /**
     * @return array<int, array{janv_juin: float, juil_dec: float}>
     */
    private function semestresFromFactures(): array
    {
        $sql = <<<'SQL'
            SELECT CAST(strftime('%Y', date_facture) AS INT) AS annee,
                   COALESCE(SUM(CASE WHEN CAST(strftime('%m', date_facture) AS INT) BETWEEN 1 AND 6
                                     THEN ht ELSE 0 END), 0) AS janv_juin,
                   COALESCE(SUM(CASE WHEN CAST(strftime('%m', date_facture) AS INT) BETWEEN 7 AND 12
                                     THEN ht ELSE 0 END), 0) AS juil_dec
            FROM factures
            WHERE date_facture >= '2020-01-01'
            GROUP BY 1
            ORDER BY 1
        SQL;
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $y = (int) $row['annee'];
            $out[$y] = [
                'janv_juin' => (float) $row['janv_juin'],
                'juil_dec' => (float) $row['juil_dec'],
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{janv_juin: float, juil_dec: float}> $fromFactures
     * @return list<array{annee:int, janv_juin:float, juil_dec:float, annee_totale:float}>
     */
    private function mergeAnnees(array $fromFactures): array
    {
        $years = array_unique(array_merge(
            array_keys(self::EXCEL_SEMESTRES),
            array_keys($fromFactures)
        ));
        sort($years);

        $rows = [];
        foreach ($years as $y) {
            $excel = self::EXCEL_SEMESTRES[$y] ?? null;
            $fac = $fromFactures[$y] ?? null;

            if ($y <= 2024 && $excel) {
                $jj = (float) $excel['janv_juin'];
                $jd = (float) $excel['juil_dec'];
            } elseif ($y === 2025) {
                // Janv–juin 2025 = fin N4 (Excel) ; juil–déc 2025 = début N5 (factures)
                $jj = (float) ($excel['janv_juin'] ?? ($fac['janv_juin'] ?? 0.0));
                $jd = (float) ($fac['juil_dec'] ?? 0.0);
            } else {
                $jj = (float) ($fac['janv_juin'] ?? 0.0);
                $jd = (float) ($fac['juil_dec'] ?? 0.0);
            }

            $rows[] = [
                'annee' => $y,
                'janv_juin' => $jj,
                'juil_dec' => $jd,
                'annee_totale' => $jj + $jd,
            ];
        }
        return $rows;
    }

    /**
     * Progression :
     * - 2021-22 … 2024-25 : juil.–juin (12 mois, historique Excel) ;
     * - 2025-26 : exercice long juil. 2025 → déc. 2026 (18 mois) ;
     * - 2027+ : années civiles (depuis exercices en base ou plage calendaire).
     *
     * @param list<array{annee:int, janv_juin:float, juil_dec:float, annee_totale:float}> $annees
     * @return list<array{label:string, ca_ht:float, evolution:?float, kind:string, debut:string, fin:string}>
     */
    private function buildProgression(array $annees): array
    {
        $byYear = [];
        foreach ($annees as $r) {
            $byYear[$r['annee']] = $r;
        }

        $periods = [];

        // 1) Historique fiscal juil. Y → juin Y+1
        foreach ([2021, 2022, 2023, 2024] as $y) {
            if (!isset($byYear[$y], $byYear[$y + 1])) {
                continue;
            }
            $periods[] = [
                'label' => sprintf('%d-%02d', $y, ($y + 1) % 100),
                'ca_ht' => $byYear[$y]['juil_dec'] + $byYear[$y + 1]['janv_juin'],
                'kind' => 'fiscal_juil_juin',
                'debut' => sprintf('%d-07-01', $y),
                'fin' => sprintf('%d-06-30', $y + 1),
            ];
        }

        // 2) Exercice long N5 (18 mois) — pas la formule juil+janv seule
        $periods[] = [
            'label' => '2025-26',
            'ca_ht' => $this->caHtBetween(self::N5_DEBUT, self::N5_FIN),
            'kind' => 'exercice_long',
            'debut' => self::N5_DEBUT,
            'fin' => self::N5_FIN,
        ];

        // 3) Années civiles à partir de 2027
        $today = date('Y-m-d');
        $lastCivil = max(2027, (int) date('Y'));
        // Inclure les exercices civils déjà créés en base
        $st = $this->pdo->query(
            "SELECT code, libelle, date_debut, date_fin FROM exercices
             WHERE date_debut >= '" . self::CIVILE_DEBUT . "'
             ORDER BY date_debut"
        );
        $fromDb = $st ? $st->fetchAll() : [];
        $covered = [];
        foreach ($fromDb as $ex) {
            $debut = (string) $ex['date_debut'];
            $fin = (string) $ex['date_fin'];
            $y = (int) substr($debut, 0, 4);
            $covered[$y] = true;
            $ca = $this->caHtBetween($debut, $fin);
            if ($ca <= 0.0 && $fin > $today) {
                continue; // pas encore de CA sur un exercice futur
            }
            $periods[] = [
                'label' => preg_match('/^\d{4}$/', (string) $ex['code'])
                    ? (string) $ex['code']
                    : (string) $y,
                'ca_ht' => $ca,
                'kind' => 'annee_civile',
                'debut' => $debut,
                'fin' => $fin,
            ];
        }
        for ($y = 2027; $y <= $lastCivil; $y++) {
            if (isset($covered[$y])) {
                continue;
            }
            $debut = sprintf('%d-01-01', $y);
            $fin = sprintf('%d-12-31', $y);
            $ca = $this->caHtBetween($debut, $fin);
            if ($ca <= 0.0 && $debut > $today) {
                continue;
            }
            if ($ca <= 0.0 && $y === 2027 && $today < self::CIVILE_DEBUT) {
                continue;
            }
            $periods[] = [
                'label' => (string) $y,
                'ca_ht' => $ca,
                'kind' => 'annee_civile',
                'debut' => $debut,
                'fin' => $fin,
            ];
        }

        $prev = null;
        foreach ($periods as &$p) {
            $evo = null;
            if ($prev !== null && $prev > 0) {
                $evo = ($p['ca_ht'] / $prev) - 1.0;
            }
            $p['evolution'] = $evo;
            $prev = $p['ca_ht'];
        }
        unset($p);

        return $periods;
    }

    private function caHtBetween(string $debut, string $fin): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(ht), 0) FROM factures
             WHERE date_facture >= ? AND date_facture <= ? AND date_facture >= \'2020-01-01\''
        );
        $st->execute([$debut, $fin]);
        return (float) $st->fetchColumn();
    }

    /**
     * @return list<array{mois:string, label:string, ca_ht:float}>
     */
    private function mensuelExercice(int $exerciceId): array
    {
        $st = $this->pdo->prepare(
            "SELECT strftime('%Y-%m', date_facture) AS mois, COALESCE(SUM(ht),0) AS ca_ht
             FROM factures
             WHERE exercice_id = ? AND date_facture >= '2020-01-01'
             GROUP BY 1
             ORDER BY 1"
        );
        $st->execute([$exerciceId]);
        $moisFr = [
            '01' => 'janv.', '02' => 'févr.', '03' => 'mars', '04' => 'avr.',
            '05' => 'mai', '06' => 'juin', '07' => 'juil.', '08' => 'août',
            '09' => 'sept.', '10' => 'oct.', '11' => 'nov.', '12' => 'déc.',
        ];
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $m = (string) $row['mois'];
            $mm = substr($m, 5, 2);
            $yy = substr($m, 2, 2);
            $out[] = [
                'mois' => $m,
                'label' => ($moisFr[$mm] ?? $mm) . ' ' . $yy,
                'ca_ht' => (float) $row['ca_ht'],
            ];
        }
        return $out;
    }
}
