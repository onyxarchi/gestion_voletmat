<?php
declare(strict_types=1);

namespace Voletmat;

/**
 * Ordre + familles de couleur = feuille Excel Prévisionnel / COMPTA ANALYTIQUE.
 * Les codes « mensuel » se paient chaque mois sur toute la durée de l’exercice
 * (ex. 18 mois pour N5) ; les autres restent un total de période (pas × nb mois).
 */
final class TriLignesExcel
{
    /**
     * @var array<string, array{ordre:int, famille:string, mensuel?:bool}>
     */
    public const LIGNES = [
        'REM' => ['ordre' => 1, 'famille' => 'salaire', 'mensuel' => true],
        'DIVIDENDES' => ['ordre' => 2, 'famille' => 'salaire'],
        'CCA' => ['ordre' => 3, 'famille' => 'salaire'],
        'IK' => ['ordre' => 4, 'famille' => 'bleu_gris'],
        'CESU' => ['ordre' => 5, 'famille' => 'bleu_gris'],
        'FORMATION' => ['ordre' => 6, 'famille' => 'bleu_gris'],
        'URSSAF' => ['ordre' => 7, 'famille' => 'vert', 'mensuel' => true],
        'PREV' => ['ordre' => 8, 'famille' => 'vert', 'mensuel' => true],
        'PER' => ['ordre' => 9, 'famille' => 'vert', 'mensuel' => true],
        'PJ' => ['ordre' => 10, 'famille' => 'vert', 'mensuel' => true],
        'SMABTP' => ['ordre' => 11, 'famille' => 'vert', 'mensuel' => true],
        'OA' => ['ordre' => 12, 'famille' => 'vert'],
        'ASS BUREAU' => ['ordre' => 13, 'famille' => 'vert'],
        'CFE' => ['ordre' => 14, 'famille' => 'orange'],
        'IS' => ['ordre' => 15, 'famille' => 'orange'],
        'COMPTA' => ['ordre' => 16, 'famille' => 'orange', 'mensuel' => true],
        'JURIDIQUE' => ['ordre' => 17, 'famille' => 'orange'],
        'TVA' => ['ordre' => 18, 'famille' => 'orange'],
        'RECOUV' => ['ordre' => 19, 'famille' => 'orange'],
        'ASSOS' => ['ordre' => 20, 'famille' => 'cyan'],
        'FOURN' => ['ordre' => 21, 'famille' => 'cyan'],
        'INFORMATIQUE' => ['ordre' => 22, 'famille' => 'cyan'],
        'LOGICIEL' => ['ordre' => 23, 'famille' => 'cyan', 'mensuel' => true],
        'ARCHICAD' => ['ordre' => 24, 'famille' => 'cyan'],
        'SITE' => ['ordre' => 25, 'famille' => 'cyan'],
        'RESTO' => ['ordre' => 26, 'famille' => 'cyan'],
        'POSTE' => ['ordre' => 27, 'famille' => 'cyan'],
        'TEL' => ['ordre' => 28, 'famille' => 'cyan', 'mensuel' => true],
        'NET' => ['ordre' => 29, 'famille' => 'cyan', 'mensuel' => true],
        'DEPLACEMENT' => ['ordre' => 30, 'famille' => 'cyan'],
        'BANQUE' => ['ordre' => 31, 'famille' => 'navy', 'mensuel' => true],
        'EPARGNE' => ['ordre' => 32, 'famille' => 'navy'],
        'ADMIN' => ['ordre' => 33, 'famille' => 'neutre'],
        'SST' => ['ordre' => 34, 'famille' => 'neutre'],
        'BUREAU' => ['ordre' => 35, 'famille' => 'vert_bureau', 'mensuel' => true],
        'DIVERS' => ['ordre' => 36, 'famille' => 'neutre'],
    ];

    /** @return array{ordre:int, famille:string, mensuel?:bool} */
    public static function meta(string $code): array
    {
        $key = strtoupper(trim($code));
        return self::LIGNES[$key] ?? ['ordre' => 1000, 'famille' => 'extra'];
    }

    public static function ordre(string $code): int
    {
        return self::meta($code)['ordre'];
    }

    public static function famille(string $code): string
    {
        return self::meta($code)['famille'];
    }

    /** Charge récurrente chaque mois sur toute la durée de l’exercice. */
    public static function estMensuel(string $code): bool
    {
        return !empty(self::meta($code)['mensuel']);
    }
}
