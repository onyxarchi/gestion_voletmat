<?php
declare(strict_types=1);

namespace Voletmat\Importers;

use RuntimeException;
use ZipArchive;

/**
 * Import d’un relevé CIC exporté en Excel (.xlsx), format observé dans comptes.xlsx.
 * Aucun montant n’est inventé : seules les cellules Date / Valeur / Libellé / Débit / Crédit sont lues.
 */
final class CicExcelImporter
{
    /**
     * @return array{
     *   compte:?string,
     *   solde_final:?float,
     *   lignes: list<array{
     *     ligne_no:int,
     *     date_operation:?string,
     *     date_valeur:?string,
     *     libelle:?string,
     *     debit:?float,
     *     credit:?float,
     *     statut:string,
     *     motif:?string,
     *     empreinte:?string
     *   }>
     * }
     */
    public function parse(string $xlsxPath): array
    {
        if (!is_file($xlsxPath)) {
            throw new RuntimeException('Fichier introuvable.');
        }

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new RuntimeException('Impossible d’ouvrir le fichier Excel (xlsx).');
        }

        $shared = $this->readSharedStrings($zip);
        $sheetNames = $this->sheetNames($zip);
        $target = $this->pickAccountSheet($sheetNames);
        if ($target === null) {
            $zip->close();
            throw new RuntimeException('Aucune feuille de compte CIC trouvée (attendu : « Cpt … »).');
        }

        $rel = $target['path'];
        $xml = $zip->getFromName($rel);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('Feuille Excel illisible.');
        }

        $rows = $this->sheetToRows($xml, $shared);
        return $this->extractMovements($rows, $target['name']);
    }

    /** @return list<string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return [];
        }
        $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string) $si->t;
            } else {
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
            }
            $out[] = $t;
        }
        return $out;
    }

    /** @return list<array{name:string,path:string}> */
    private function sheetNames(ZipArchive $zip): array
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return [];
        }
        $rx = simplexml_load_string($rels);
        $map = [];
        foreach ($rx->Relationship as $rel) {
            $map[(string) $rel['Id']] = 'xl/' . ltrim((string) $rel['Target'], '/');
        }
        $wx = simplexml_load_string($wb);
        $wx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $wx->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = [];
        foreach ($wx->sheets->sheet as $s) {
            $rid = (string) $s->attributes('r', true)['id'];
            $sheets[] = [
                'name' => (string) $s['name'],
                'path' => $map[$rid] ?? '',
            ];
        }
        return $sheets;
    }

    /** @param list<array{name:string,path:string}> $sheets */
    private function pickAccountSheet(array $sheets): ?array
    {
        foreach ($sheets as $s) {
            if (str_starts_with($s['name'], 'Cpt ') && $s['path'] !== '') {
                return $s;
            }
        }
        return null;
    }

    /**
     * @param list<string> $shared
     * @return array<int, array<int, mixed>>
     */
    private function sheetToRows(string $xml, array $shared): array
    {
        $sx = simplexml_load_string($xml);
        if ($sx === false) {
            throw new RuntimeException('XML de feuille invalide.');
        }
        $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $rIdx = (int) $row['r'];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $col = $this->colIndex($m[1]);
                $type = (string) ($c['t'] ?? '');
                $raw = isset($c->v) ? (string) $c->v : '';
                if ($type === 's') {
                    $val = $shared[(int) $raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string) $c->is->t : '';
                } elseif ($raw === '') {
                    $val = null;
                } elseif (is_numeric($raw)) {
                    $val = str_contains($raw, '.') ? (float) $raw : (0 + $raw);
                } else {
                    $val = $raw;
                }
                $rows[$rIdx][$col] = $val;
            }
        }
        ksort($rows);
        return $rows;
    }

    private function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array{compte:?string,solde_final:?float,lignes:list<array<string,mixed>>}
     */
    private function extractMovements(array $rows, string $sheetName): array
    {
        $headerRow = null;
        foreach ($rows as $r => $cols) {
            $a = isset($cols[1]) ? trim((string) $cols[1]) : '';
            if ($a === 'Date' && isset($cols[3]) && trim((string) $cols[3]) === 'Libellé') {
                $headerRow = $r;
                break;
            }
        }
        if ($headerRow === null) {
            throw new RuntimeException('En-tête Date / Libellé / Débit / Crédit introuvable dans la feuille « ' . $sheetName . ' ».');
        }

        $lignes = [];
        $no = 0;
        foreach ($rows as $r => $cols) {
            if ($r <= $headerRow) {
                continue;
            }
            $lib = isset($cols[3]) ? trim((string) $cols[3]) : '';
            if ($lib === '' || str_starts_with($lib, 'Solde') || str_starts_with($lib, 'Liste de')) {
                continue;
            }
            $no++;
            $dateOp = $this->excelDateToIso($cols[1] ?? null);
            $dateVal = $this->excelDateToIso($cols[2] ?? null);
            $debit = $this->toAmount($cols[4] ?? null);
            $credit = $this->toAmount($cols[5] ?? null);

            $statut = 'ok';
            $motif = null;
            if ($dateOp === null) {
                $statut = 'incertain';
                $motif = 'Date d’opération absente ou illisible';
            } elseif ($debit === null && $credit === null) {
                $statut = 'incertain';
                $motif = 'Ni débit ni crédit (aucun montant inventé)';
            } elseif ($debit !== null && $credit !== null) {
                $statut = 'incertain';
                $motif = 'Débit et crédit tous deux renseignés';
            }

            $emp = ($dateOp !== null)
                ? hash('sha256', $dateOp . '|' . $lib . '|' . ($debit === null ? '' : number_format($debit, 2, '.', '')) . '|' . ($credit === null ? '' : number_format($credit, 2, '.', '')))
                : null;

            $lignes[] = [
                'ligne_no' => $no,
                'date_operation' => $dateOp,
                'date_valeur' => $dateVal,
                'libelle' => $lib,
                'debit' => $debit,
                'credit' => $credit,
                'statut' => $statut,
                'motif' => $motif,
                'empreinte' => $emp,
            ];
        }

        $solde = null;
        foreach ($rows as $cols) {
            foreach ($cols as $v) {
                if (is_string($v) && str_contains($v, 'Solde au')) {
                    // solde souvent en col F
                }
            }
            if (isset($cols[4]) && is_string($cols[4]) && str_contains($cols[4], 'Solde au') && isset($cols[6]) && is_numeric($cols[6])) {
                $solde = (float) $cols[6];
            }
        }

        return [
            'compte' => $sheetName,
            'solde_final' => $solde,
            'lignes' => $lignes,
        ];
    }

    private function excelDateToIso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        if (is_numeric($v)) {
            // Série Excel (jours depuis 1899-12-30)
            $n = (float) $v;
            if ($n > 20000 && $n < 80000) {
                $ts = (int) round(($n - 25569) * 86400);
                return gmdate('Y-m-d', $ts);
            }
        }
        if (is_string($v)) {
            $d = date_create($v);
            return $d ? $d->format('Y-m-d') : null;
        }
        return null;
    }

    private function toAmount(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v)) {
            $s = str_replace(["\u{00A0}", ' ', '€'], '', $v);
            $s = str_replace(',', '.', $s);
            if ($s === '' || !is_numeric($s)) {
                return null;
            }
            return (float) $s;
        }
        return null;
    }
}
