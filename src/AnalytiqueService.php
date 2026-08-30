<?php
declare(strict_types=1);

namespace Voletmat;

use PDO;

/**
 * Compta analytique : TRI × mois depuis la banque (pas de double comptage).
 */
final class AnalytiqueService
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
     *   lignes: list<array{code:string, libelle:string, famille:string, mois: array<string,float>, total:float}>,
     *   totaux_mois: array<string, float>,
     *   total_general: float
     * }
     */
    public function grille(int $exerciceId): array
    {
        $st = $this->pdo->prepare(
            'SELECT annee_mois,
                    categorie_code,
                    COALESCE(SUM(ABS(COALESCE(debit, 0))), 0) AS debits,
                    COALESCE(SUM(COALESCE(credit, 0)), 0) AS credits
             FROM operations_bancaires
             WHERE exercice_id = ?
               AND annee_mois IS NOT NULL
               AND annee_mois != \'\'
             GROUP BY annee_mois, categorie_code
             ORDER BY annee_mois, categorie_code'
        );
        $st->execute([$exerciceId]);
        $rows = $st->fetchAll();

        $moisSet = [];
        $byCat = [];
        foreach ($rows as $r) {
            $m = (string) $r['annee_mois'];
            $code = (string) ($r['categorie_code'] ?? '');
            if ($code === '') {
                $code = '—';
            }
            $moisSet[$m] = true;
            // Débit = charge (positif) ; crédit VENTE = produit (positif dédié)
            $montant = (float) $r['debits'];
            if (strtoupper($code) === 'VENTE') {
                $montant = (float) $r['credits'];
            } elseif ((float) $r['credits'] > 0 && (float) $r['debits'] < 0.005) {
                $montant = (float) $r['credits'];
            }
            $byCat[$code][$m] = ($byCat[$code][$m] ?? 0.0) + $montant;
        }

        // Mois de l’exercice même sans mouvement
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

        $libelles = [];
        foreach ($this->pdo->query('SELECT code, libelle FROM categories')->fetchAll() as $c) {
            $libelles[(string) $c['code']] = (string) $c['libelle'];
        }

        $totauxMois = array_fill_keys($moisKeys, 0.0);
        $lignes = [];
        foreach ($byCat as $code => $vals) {
            $total = 0.0;
            $moisVals = [];
            foreach ($moisKeys as $mk) {
                $v = round((float) ($vals[$mk] ?? 0.0), 2);
                $moisVals[$mk] = $v;
                $total += $v;
                $totauxMois[$mk] += $v;
            }
            $lignes[] = [
                'code' => $code,
                'libelle' => $libelles[$code] ?? $code,
                'famille' => TriLignesExcel::famille($code),
                'mois' => $moisVals,
                'total' => round($total, 2),
            ];
        }
        usort($lignes, static function (array $a, array $b): int {
            $oa = TriLignesExcel::ordre($a['code']);
            $ob = TriLignesExcel::ordre($b['code']);
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcasecmp($a['code'], $b['code']);
        });

        foreach ($totauxMois as $k => $v) {
            $totauxMois[$k] = round($v, 2);
        }

        return [
            'mois' => $mois,
            'lignes' => $lignes,
            'totaux_mois' => $totauxMois,
            'total_general' => round(array_sum($totauxMois), 2),
        ];
    }

    /** @return list<string> */
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
}
