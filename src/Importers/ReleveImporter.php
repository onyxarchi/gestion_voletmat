<?php
declare(strict_types=1);

namespace Voletmat\Importers;

use RuntimeException;

/**
 * Point d’entrée import relevé CIC : Excel (.xlsx) ou PDF.
 */
final class ReleveImporter
{
    /**
     * @return array{
     *   compte:?string,
     *   solde_initial:?float,
     *   solde_final:?float,
     *   ecart_solde:?float,
     *   lignes: list<array<string,mixed>>,
     *   format: string
     * }
     */
    public function parse(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $parsed = match ($ext) {
            'xlsx' => (new CicExcelImporter())->parse($path),
            'pdf' => (new CicPdfImporter())->parse($path),
            'xls' => throw new RuntimeException('Format .xls non supporté : enregistrez en .xlsx ou PDF.'),
            'csv' => throw new RuntimeException('CSV : à venir. Utilisez .xlsx ou PDF CIC.'),
            default => throw new RuntimeException('Format non supporté (attendu : .xlsx ou .pdf).'),
        };
        $parsed['format'] = $ext === 'pdf' ? 'cic_pdf' : 'cic_xlsx';
        return $parsed;
    }
}
