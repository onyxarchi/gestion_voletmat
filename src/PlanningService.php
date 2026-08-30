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
            'SELECT e.affaire_id, e.annee_mois, e.montant_ht, e.statut, e.couleur
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

        return [
            'mois' => $mois,
            'lignes' => $lignes,
            'totaux_mois' => $totauxMois,
            'totaux_statut' => $totauxStatut,
            'totaux_synthese' => $totauxSynthese,
        ];
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
