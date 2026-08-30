<?php
declare(strict_types=1);

namespace Voletmat\Importers;

use RuntimeException;

/**
 * Import d’un relevé CIC / Crédit Mutuel en PDF.
 * Aucun montant inventé : seules date, libellé et montants lus dans le texte.
 */
final class CicPdfImporter
{
    public function __construct(private ?PdfTextExtractor $extractor = null)
    {
        $this->extractor ??= new PdfTextExtractor();
    }

    /**
     * @return array{
     *   compte:?string,
     *   solde_initial:?float,
     *   solde_final:?float,
     *   solde_initial_deduit:bool,
     *   ecart_solde:?float,
     *   sum_debit:float,
     *   sum_credit:float,
     *   lignes: list<array<string,mixed>>
     * }
     */
    public function parse(string $pdfPath): array
    {
        $text = $this->extractor->extract($pdfPath);
        return $this->parseText($text, basename($pdfPath));
    }

    /**
     * Point d’entrée testable (texte déjà extrait).
     *
     * @return array<string,mixed>
     */
    public function parseText(string $text, string $compte = 'relevé PDF'): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        // Extraction PDF souvent latin1 / octets invalides → casserait les regex /u
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: $text;

        // Extraction PHP (sans pdftotext) : une colonne = une ligne → réassembler
        $text = $this->reassembleColumnarText($text);

        $lines = explode("\n", $text);

        $soldes = $this->findSoldesCrediteur($text);
        $soldeInitial = $soldes['initial'] ?? $this->findSolde($text, true);
        $soldeFinal = $soldes['final'] ?? $this->findSolde($text, false);

        $rawOps = [];
        $pendingLib = '';
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line === '' || $this->isNoiseLine($line)) {
                continue;
            }

            $parsed = $this->parseOperationLine($line);
            if ($parsed !== null) {
                if ($pendingLib !== '' && $rawOps !== []) {
                    $extra = self::sanitizeLibelle($pendingLib);
                    if ($extra !== '') {
                        $rawOps[array_key_last($rawOps)]['libelle'] .= ' ' . $extra;
                    }
                    $pendingLib = '';
                }
                $parsed['libelle'] = self::sanitizeLibelle((string) $parsed['libelle']);
                if ($parsed['libelle'] === '') {
                    continue;
                }
                $rawOps[] = $parsed;
                continue;
            }

            // Suite de libellé (ligne sans date) — ignorer pieds de page CIC
            if ($rawOps !== [] && !$this->isNoiseLine($line) && !preg_match('/solde/ui', $line)) {
                if (preg_match('/^\d{1,2}[\/.]\d{1,2}/', $line)) {
                    continue;
                }
                $chunk = self::sanitizeLibelle($line);
                if ($chunk === '') {
                    // Pied de page atteint : ne plus fusionner
                    $pendingLib = '';
                    continue;
                }
                $pendingLib = trim($pendingLib . ' ' . $chunk);
            }
        }
        if ($pendingLib !== '' && $rawOps !== []) {
            $extra = self::sanitizeLibelle($pendingLib);
            if ($extra !== '') {
                $rawOps[array_key_last($rawOps)]['libelle'] .= ' ' . $extra;
            }
        }

        if ($rawOps === []) {
            throw new RuntimeException(
                'Aucune opération lisible dans le PDF. '
                . 'Vérifiez qu’il s’agit d’un relevé CIC texte (pas un scan image), '
                . 'ou exportez en Excel (.xlsx) depuis CIC.'
            );
        }

        // Déterminer débit/crédit via soldes courants si possible
        $this->assignDebitCredit($rawOps, $soldeInitial);

        $lignes = [];
        $no = 0;
        $seenKeys = [];
        $sumDebit = 0.0;
        $sumCredit = 0.0;

        foreach ($rawOps as $op) {
            $no++;
            $lib = self::sanitizeLibelle(trim((string) $op['libelle']));
            if ($lib === '') {
                continue;
            }
            $dateOp = $op['date_operation'];
            $dateVal = $op['date_valeur'];
            $debit = $op['debit'];
            $credit = $op['credit'];

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

            $emp = null;
            $empLegacy = null;
            if ($dateOp !== null) {
                $key = $dateOp . '|' . $lib . '|'
                    . ($debit === null ? '' : number_format($debit, 2, '.', '')) . '|'
                    . ($credit === null ? '' : number_format($credit, 2, '.', ''));
                $seenKeys[$key] = ($seenKeys[$key] ?? 0) + 1;
                $occ = $seenKeys[$key];
                $emp = hash('sha256', $key . '|' . $occ);
                if ($occ === 1) {
                    $empLegacy = hash('sha256', $key);
                }
            }

            if ($statut === 'ok') {
                $sumDebit += (float) ($debit ?? 0);
                $sumCredit += (float) ($credit ?? 0);
            }

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
                'empreinte_legacy' => $empLegacy,
            ];
        }

        $ecartSolde = null;
        $soldeInitialDeduit = false;
        if ($soldeFinal !== null && $soldeInitial !== null) {
            // Comme Excel CIC : débits souvent négatifs → solde_final ≈ initial + crédits + débits
            $calcule = $soldeInitial + $sumCredit + $sumDebit;
            $ecartSolde = round($soldeFinal - $calcule, 2);
        } elseif ($soldeFinal !== null) {
            $soldeInitial = round($soldeFinal - $sumCredit - $sumDebit, 2);
            $soldeInitialDeduit = true;
            $ecartSolde = null;
        }

        return [
            'compte' => $compte,
            'solde_initial' => $soldeInitial,
            'solde_final' => $soldeFinal,
            'solde_initial_deduit' => $soldeInitialDeduit,
            'ecart_solde' => $ecartSolde,
            'sum_debit' => round($sumDebit, 2),
            'sum_credit' => round($sumCredit, 2),
            'lignes' => $lignes,
        ];
    }

    /**
     * Sans pdftotext -layout, l’extracteur PHP sort une cellule par ligne :
     * date / date valeur / libellé / montant / suite. On reconstitue des lignes ops.
     */
    private function reassembleColumnarText(string $text): string
    {
        $rawLines = explode("\n", $text);
        $dateOnly = 0;
        $inlineOps = 0;
        foreach ($rawLines as $ln) {
            $t = trim($ln);
            if (preg_match('/^\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}$/', $t)) {
                $dateOnly++;
            }
            if (preg_match(
                '/^\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\s+\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\s+\S+.+\d,\d{2}/u',
                $t
            )) {
                $inlineOps++;
            }
        }
        // Déjà en lignes complètes (pdftotext -layout) → ne rien faire
        if ($inlineOps >= 3 || $dateOnly < 8) {
            return $text;
        }

        $amtRe = '/^[+-]?(?:\d{1,3}(?:[.\s\x{00A0}]\d{3})+|\d+),\d{2}$/u';
        $dateRe = '/^\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}$/';
        $out = [];
        $i = 0;
        $n = count($rawLines);
        while ($i < $n) {
            $line = trim(preg_replace('/\s+/u', ' ', $rawLines[$i]) ?? $rawLines[$i]);
            if ($line === '') {
                $i++;
                continue;
            }
            // Soldes : garder date + montant sur 2 lignes pour findSoldesCrediteur
            if (preg_match('/^SOLDE\s+(CREDITEUR|DEBITEUR)\s+AU\s+(\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4})$/iu', $line, $sm)) {
                $j = $i + 1;
                while ($j < $n && trim($rawLines[$j]) === '') {
                    $j++;
                }
                $amtLine = $j < $n ? trim($rawLines[$j]) : '';
                if ($amtLine !== '' && preg_match($amtRe, $amtLine)) {
                    $out[] = $line . ' ' . $amtLine;
                    $i = $j + 1;
                    continue;
                }
            }
            if (!preg_match($dateRe, $line)) {
                // Conserver libellés utiles hors ops (sinon bruit écrasé)
                if (!$this->isNoiseLine($line) && !preg_match('/^(Date|Opération|Débit|Crédit)/iu', $line)) {
                    $out[] = $line;
                }
                $i++;
                continue;
            }

            $dateOp = $line;
            $i++;
            while ($i < $n && trim($rawLines[$i]) === '') {
                $i++;
            }
            $dateVal = $dateOp;
            if ($i < $n) {
                $maybe = trim($rawLines[$i]);
                if (preg_match($dateRe, $maybe)) {
                    $dateVal = $maybe;
                    $i++;
                }
            }

            $libParts = [];
            $amount = null;
            while ($i < $n) {
                $t = trim(preg_replace('/\s+/u', ' ', $rawLines[$i]) ?? $rawLines[$i]);
                if ($t === '') {
                    $i++;
                    continue;
                }
                if (preg_match($dateRe, $t)) {
                    break; // prochaine op
                }
                if (preg_match('/^SOLDE\s+(CREDITEUR|DEBITEUR)\b/iu', $t)) {
                    break;
                }
                if ($this->isNoiseLine($t) || preg_match('/^(Date|Date valeur|Opération|Débit|Crédit|Page\s+\d)/iu', $t)) {
                    $i++;
                    continue;
                }
                if ($amount === null && preg_match($amtRe, $t)) {
                    $amount = $t;
                    $i++;
                    continue;
                }
                // Après le montant : suite de libellé (commerçant, refs)
                if ($amount !== null && preg_match($amtRe, $t)) {
                    // Totaux / 2e montant → fin du bloc
                    break;
                }
                $libParts[] = $t;
                $i++;
                // Sécurité : ne pas avaler toute la page
                if (count($libParts) > 12 && $amount !== null) {
                    break;
                }
            }

            if ($amount === null || $libParts === []) {
                continue;
            }
            $lib = trim(implode(' ', $libParts));
            $out[] = $dateOp . ' ' . $dateVal . ' ' . $lib . ' ' . $amount;
        }

        return implode("\n", $out);
    }

    /**
     * @return array{initial:?float,final:?float}
     */
    private function findSoldesCrediteur(string $text): array
    {
        $amt = '([+-]?(?:\d{1,3}(?:[.\s\x{00A0}]\d{3})+|\d+),\d{2})';
        $found = [];
        if (preg_match_all(
            '/SOLDE\s+(?:CREDITEUR|DEBITEUR)\s+AU\s+(\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4})\s+' . $amt . '/ui',
            $text,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $mm) {
                $iso = $this->frDateToIso($mm[1]);
                $a = $this->extractAmounts($mm[2]);
                if ($iso === null || $a === []) {
                    continue;
                }
                $found[] = ['date' => $iso, 'value' => $a[0]['value']];
            }
        }
        if ($found === []) {
            return ['initial' => null, 'final' => null];
        }
        usort($found, static fn (array $x, array $y): int => strcmp($x['date'], $y['date']));
        return [
            'initial' => $found[0]['value'],
            'final' => $found[array_key_last($found)]['value'],
        ];
    }

    private function isNoiseLine(string $line): bool
    {
        if ($this->looksLikeHeader($line)) {
            return true;
        }
        $u = mb_strtoupper($line, 'UTF-8');
        foreach ([
            'PAGE ', 'WWW.', 'CIC.FR', 'CREDIT MUTUEL', 'IBAN', 'BIC ', 'TITULAIRE',
            'RELEVE DE COMPTE', 'TOTAL DES MOUVEMENTS', 'INFORMATION SUR LA PROTECTION',
            'VOUS DISPOSEZ', 'GARANTIE DE L', 'GARANTIE DES DEPOTS', 'BANQUE CIC',
            'POUR TOUTE DEMANDE', 'APPEL NON SURTAXE', 'CODE MONETAIRE', 'SUITE AU VERSO',
            'QUAI DES CHARTRONS', 'SA AU CAPITAL',
        ] as $n) {
            if (str_contains($u, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Coupe pieds de page CIC + compacte les PRLV SEPA (créancier utile seulement).
     */
    public static function sanitizeLibelle(string $libelle): string
    {
        $lib = trim(preg_replace('/\s+/u', ' ', $libelle) ?? $libelle);
        if ($lib === '') {
            return '';
        }
        $cutters = [
            '/\bTotal des mouvements\b.*/ui',
            '/\bInformation sur la protection des comptes\b.*/ui',
            '/\bVous disposez d[\'’]une carte\b.*/ui',
            '/\bBanque CIC\b.*/ui',
            '/\(GE\)\s*:\s*protég.*/ui',
            '/\bPour toute demande\b.*/ui',
            '/<<\s*Suite au verso\s*>>.*/ui',
            '/\bSA au capital\b.*/ui',
            '/\bQuai des Chartrons\b.*/ui',
            '/\bCode Monétaire\b.*/ui',
            '/\bappel non surtaxé\b.*/ui',
            '/\bSous réserve des extournes\b.*/ui',
            '/\bX\s+0\s+[2V]\b.*/ui',
        ];
        foreach ($cutters as $re) {
            $lib = preg_replace($re, '', $lib) ?? $lib;
        }
        $lib = preg_replace('/\bUN\.\d[\d.]*/u', ' ', $lib) ?? $lib;
        $lib = preg_replace('/\s+/u', ' ', $lib) ?? $lib;
        $lib = trim($lib);

        if (preg_match('/^PRLV\s+SEPA\b/iu', $lib)) {
            $lib = self::compactPrlv($lib);
        }

        return $lib;
    }

    /**
     * PRLV SEPA SMABTP … adresse ICS RUM → « PRLV SEPA SMABTP ».
     * Conserve une référence métier courte si utile (TVA1-…, GTC…, PRE-…).
     */
    private static function compactPrlv(string $lib): string
    {
        $lib = preg_replace('/\bICS\s*:.*$/iu', '', $lib) ?? $lib;
        $lib = preg_replace('/\bRUM\s*:.*$/iu', '', $lib) ?? $lib;
        $lib = preg_replace('/\bMANDAT\s*:.*$/iu', '', $lib) ?? $lib;
        $lib = preg_replace('/\b\d{1,4}\s+(RUE|AV(?:ENUE)?|BD|BOULEVARD|QUAI|CHEMIN|IMPASSE|PLACE|ALL[EÉ]E)\b.*$/iu', '', $lib) ?? $lib;
        $lib = preg_replace('/\b\d{5}\s+[A-ZÀ-Ü].*$/u', '', $lib) ?? $lib;
        $lib = preg_replace('/\b(?=[A-Z0-9]*\d)[A-Z0-9]{12,}\b/u', ' ', $lib) ?? $lib;
        $lib = preg_replace('/\b\d{10,}\b/u', ' ', $lib) ?? $lib;
        $lib = preg_replace('/\b[A-Z0-9]+\/[A-Z0-9\/\-]+/iu', ' ', $lib) ?? $lib;
        $lib = preg_replace('/\bPRELEVEMENT\b.*$/iu', '', $lib) ?? $lib;
        $lib = preg_replace('/\s+/u', ' ', $lib) ?? $lib;
        $lib = trim($lib, " \t-–/");

        if (!preg_match('/^(PRLV\s+SEPA)\s+(.+)$/iu', $lib, $m)) {
            return $lib;
        }

        $rest = trim($m[2]);
        $restU = mb_strtoupper($rest, 'UTF-8');

        $refs = [];
        if (preg_match_all('/\b(TVA1?-[A-Z0-9\-]+|IS-[A-Z0-9\-]+|RCM-[A-Z0-9\-]+|GTC\d+|PRE-[A-Z0-9\-]+)/iu', $rest, $rm)) {
            foreach ($rm[1] as $ref) {
                $refs[mb_strtoupper($ref, 'UTF-8')] = $ref;
            }
        }

        // Créanciers connus (plus long d’abord)
        $creditors = [
            'URSSAF MIDI PYRENEES',
            'COMPAGNIE FIDUCIAIRE',
            'SWISSLIFE PREVOYANCE',
            'GGVIE - PERIN COL GEV',
            'GROUPAMA PROTECTION J',
            'DIR. GENE. DES FINANCES PUBLIQUES',
            'BOUYGUES TELECOM',
            'FREE TELECOM',
            'FREE MOBILE',
            'VISEEON',
            'SMABTP',
            'DGFIP',
            'URSSAF',
            'GPJ',
        ];
        $cred = null;
        foreach ($creditors as $c) {
            if (str_contains($restU, mb_strtoupper($c, 'UTF-8'))) {
                $cred = $c;
                break;
            }
        }

        if ($cred === null) {
            $parts = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out = [];
            $prev = null;
            foreach ($parts as $p) {
                if (preg_match('/^(TVA1?-|IS-|RCM-|GTC|PRE-)/iu', $p)) {
                    break;
                }
                if (preg_match('/^\d/', $p) || preg_match('/^(PARIS|ICS|RUM|FR\d)/iu', $p)) {
                    break;
                }
                $u = mb_strtoupper($p, 'UTF-8');
                if ($prev === $u) {
                    continue;
                }
                $out[] = $p;
                $prev = $u;
                if (count($out) >= 4) {
                    break;
                }
            }
            $cred = implode(' ', $out);
            // Dédupliquer « A B A B »
            if (preg_match('/^(.+?)\s+\1$/u', $cred, $dup)) {
                $cred = $dup[1];
            }
        }

        $bits = ['PRLV SEPA'];
        if ($cred !== null && $cred !== '') {
            $bits[] = $cred;
        }
        foreach ($refs as $ref) {
            // Éviter de répéter un fragment déjà dans le créancier
            if ($cred !== null && str_contains(mb_strtoupper($cred, 'UTF-8'), mb_strtoupper($ref, 'UTF-8'))) {
                continue;
            }
            $bits[] = $ref;
        }

        return trim(implode(' ', $bits));
    }

    private function looksLikeHeader(string $line): bool
    {
        return (bool) preg_match('/^(date|libell[eé]|d[eé]bit|cr[eé]dit|op[eé]ration|d[eé]tail)/ui', $line)
            || (bool) preg_match('/date\s+valeur/ui', $line);
    }

    /**
     * @return array{date_operation:?string,date_valeur:?string,libelle:string,montant:?float,solde:?float,debit:?float,credit:?float}|null
     */
    private function parseOperationLine(string $line): ?array
    {
        // DD/MM/YYYY DD/MM/YYYY libellé montants…
        if (!preg_match(
            '/^(\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4})(?:\s+(\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}))?\s+(.+)$/u',
            $line,
            $m
        )) {
            return null;
        }

        $dateOp = $this->frDateToIso($m[1]);
        $dateVal = isset($m[2]) && $m[2] !== '' ? $this->frDateToIso($m[2]) : $dateOp;
        $rest = trim($m[3]);

        // Montants de FIN de ligne uniquement (= colonnes Débit / Crédit / Solde du relevé).
        // Jamais les « 10,00 USD » / « 53,78 EUR » au milieu du libellé.
        $amtTok = '[+-]?(?:\d{1,3}(?:[.\s\x{00A0}]\d{3})+|\d+),\d{2}';
        $lib = $rest;
        $amounts = [];
        if (preg_match('/^(.*?)((?:\s+' . $amtTok . '){1,2})\s*$/u', $rest, $tm)) {
            $libCandidate = trim($tm[1]);
            $tailAmounts = $this->extractAmounts(trim($tm[2]));
            // Si la « queue » n’est qu’un montant devise du libellé (… 10,00 USD / 17,94 EUR), refuser
            if ($tailAmounts !== [] && !preg_match('/\b(USD|GBP|CHF|CAD|JPY|EUR)\s*$/iu', $libCandidate)) {
                $lib = $libCandidate;
                $amounts = $tailAmounts;
            }
        }

        if ($amounts === []) {
            // Repli : montants de colonnes uniquement (ignorer XX,XX USD/EUR au milieu)
            $amounts = $this->extractTrailingEuroAmounts($rest);
            if ($amounts === []) {
                return null;
            }
            $lib = $rest;
            foreach (array_reverse($amounts) as $a) {
                $lib = preg_replace(
                    '/[\s]*' . preg_quote($a['raw'], '/') . '\s*$/u',
                    '',
                    $lib,
                    1
                ) ?? $lib;
            }
            $lib = trim($lib);
        }

        // Retirer les montants devise encore collés au libellé (ex. « 10,00 USD OPENAI »)
        $lib = preg_replace(
            '/\s+[+-]?(?:\d{1,3}(?:[.\s\x{00A0}]\d{3})+|\d+),\d{2}\s+(USD|GBP|CHF|CAD|JPY|EUR)\b/iu',
            '',
            $lib
        ) ?? $lib;
        $lib = trim($lib);
        if ($lib === '') {
            return null;
        }

        $montant = null;
        $solde = null;
        $debit = null;
        $credit = null;

        if (count($amounts) >= 2) {
            $montant = $amounts[count($amounts) - 2]['value'];
            $solde = $amounts[count($amounts) - 1]['value'];
        } else {
            $v = $amounts[0]['value'];
            if ($v < 0) {
                $debit = $v;
            } elseif (str_starts_with($amounts[0]['raw'], '+')) {
                $credit = $v;
            } else {
                $montant = $v;
            }
        }

        return [
            'date_operation' => $dateOp,
            'date_valeur' => $dateVal,
            'libelle' => $lib,
            'montant' => $montant,
            'solde' => $solde,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    /**
     * Montants des colonnes relevé (€), en ignorant « 10,00 USD » / « 17,94 EUR » du libellé.
     *
     * @return list<array{raw:string,value:float}>
     */
    private function extractTrailingEuroAmounts(string $s): array
    {
        $all = $this->extractAmountsWithOffsets($s);
        if ($all === []) {
            return [];
        }
        $kept = [];
        foreach ($all as $a) {
            $after = substr($s, $a['end']);
            // Devise mentionnée juste après = montant commerçant, pas la colonne CIC
            if (preg_match('/^\s*(USD|GBP|CHF|CAD|JPY|EUR)\b/iu', $after)) {
                continue;
            }
            $kept[] = $a;
        }
        if ($kept === []) {
            return [];
        }
        // Garder 1 ou 2 derniers (= mouvement ± solde)
        $kept = array_slice($kept, -2);
        return array_map(static fn (array $a): array => [
            'raw' => $a['raw'],
            'value' => $a['value'],
        ], $kept);
    }

    /**
     * @param list<array{date_operation:?string,date_valeur:?string,libelle:string,montant:?float,solde:?float,debit:?float,credit:?float}> $ops
     */
    private function assignDebitCredit(array &$ops, ?float $soldeInitial): void
    {
        $cursor = $soldeInitial;
        foreach ($ops as &$op) {
            if ($op['debit'] !== null || $op['credit'] !== null) {
                if ($op['solde'] !== null) {
                    $cursor = $op['solde'];
                } elseif ($cursor !== null) {
                    $cursor = round($cursor + (float) ($op['credit'] ?? 0) + (float) ($op['debit'] ?? 0), 2);
                }
                continue;
            }

            $m = $op['montant'];
            if ($m === null) {
                continue;
            }

            // Priorité : continuité du solde courant
            if ($cursor !== null && $op['solde'] !== null) {
                $asCredit = round($cursor + abs($m), 2);
                $asDebit = round($cursor - abs($m), 2);
                if (abs($asCredit - $op['solde']) < 0.02) {
                    $op['credit'] = abs($m);
                    $cursor = $op['solde'];
                    continue;
                }
                if (abs($asDebit - $op['solde']) < 0.02) {
                    $op['debit'] = -abs($m); // négatif comme Excel CIC
                    $cursor = $op['solde'];
                    continue;
                }
            }

            // Heuristique libellé
            $u = mb_strtoupper($op['libelle'], 'UTF-8');
            $likelyCredit = (bool) preg_match(
                '/\b(VIR INST|VIR SEPA(?!\s+REM)|VIREMENT DE|REMBT|REMBOURSEMENT|INTERETS|STRIPE|ENCAISSEMENT|ACO\b|CB\s+PRODUIT)\b/u',
                $u
            ) && !preg_match('/\b(PRLV|PAIEMENT CB|PAIEMENT PSC|FACT DONT|FRAIS|CHEQUE|ECHEANCE)\b/u', $u);

            // Factures clients type « F26 06 111 VOL&MAT »
            if (preg_match('/\bF\d{2}\s+\d{2}\s+\d{1,4}\b/u', $u)) {
                $likelyCredit = true;
            }
            // Encaissements clients (libellés CIC courts)
            if (preg_match('/\b(CANTIER|LILIAN|DUVAL|STRIPE|PAYPAL)\b/u', $u)
                && !preg_match('/\b(PRLV|PAIEMENT|FRAIS|CHEQUE|ECHEANCE|REMUNERATION)\b/u', $u)
            ) {
                $likelyCredit = true;
            }
            if (preg_match('/^(MAT|VIR SEPA(?!\s+REM))\b/u', $u)
                && !preg_match('/\b(PRLV|PAIEMENT|FRAIS|REMUNERATION)\b/u', $u)
            ) {
                $likelyCredit = true;
            }

            // VIR SEPA REMUNERATION = débit (sortie)
            if (preg_match('/\b(PRLV|PAIEMENT|FACT DONT|FRAIS|CHEQUE|COTIS|ECHEANCE|REMUNERATION)\b/u', $u)) {
                $likelyCredit = false;
            }

            if ($likelyCredit) {
                $op['credit'] = abs($m);
            } else {
                $op['debit'] = -abs($m);
            }

            if ($op['solde'] !== null) {
                $cursor = $op['solde'];
            } elseif ($cursor !== null) {
                $cursor = round($cursor + (float) ($op['credit'] ?? 0) + (float) ($op['debit'] ?? 0), 2);
            }
        }
        unset($op);
    }

    /**
     * Montants au format relevé CIC FR : point (ou espace) = milliers, virgule = décimales.
     * Ex. « 3.038,75 » → 3038.75 — jamais 3.03875.
     *
     * @return list<array{raw:string,value:float}>
     */
    private function extractAmounts(string $s): array
    {
        return array_map(static fn (array $a): array => [
            'raw' => $a['raw'],
            'value' => $a['value'],
        ], $this->extractAmountsWithOffsets($s));
    }

    /**
     * @return list<array{raw:string,value:float,start:int,end:int}>
     */
    private function extractAmountsWithOffsets(string $s): array
    {
        $out = [];
        if (preg_match_all(
            '/([+-]?)(\d{1,3}(?:[.\s\x{00A0}]\d{3})+|\d+),(\d{2})(?!\d)/u',
            $s,
            $m,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($m as $mm) {
                $raw = $mm[0][0];
                $start = (int) $mm[0][1];
                $sign = ($mm[1][0] ?? '') === '-' ? -1.0 : 1.0;
                $intPart = preg_replace('/[.\s\x{00A0}]/u', '', $mm[2][0]) ?? $mm[2][0];
                if ($intPart === '' || !ctype_digit($intPart)) {
                    continue;
                }
                $cents = $mm[3][0];
                $out[] = [
                    'raw' => $raw,
                    'value' => $sign * ((float) $intPart + ((float) $cents / 100.0)),
                    'start' => $start,
                    'end' => $start + strlen($raw),
                ];
            }
            return $out;
        }

        // Repli rare : décimale point sans virgule (3038.75) — pas les milliers FR « 3.038 »
        if (preg_match_all('/([+-]?)(\d+)\.(\d{2})(?!\d)/u', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $mm) {
                $raw = $mm[0][0];
                $start = (int) $mm[0][1];
                $afterDot = $mm[3][0];
                // Si un 3e chiffre suit dans le texte original juste après le match, c’est des milliers
                if (isset($s[$start + strlen($raw)]) && ctype_digit($s[$start + strlen($raw)])) {
                    continue;
                }
                $sign = ($mm[1][0] ?? '') === '-' ? -1.0 : 1.0;
                $out[] = [
                    'raw' => $raw,
                    'value' => $sign * ((float) $mm[2][0] + ((float) $afterDot / 100.0)),
                    'start' => $start,
                    'end' => $start + strlen($raw),
                ];
            }
        }
        return $out;
    }

    private function findSolde(string $text, bool $initial): ?float
    {
        // Capture montant FR : 10.000,00 (point = milliers)
        $amt = '([+-]?(?:\d{1,3}(?:[.\s\x{00A0}]\d{3})+|\d+),\d{2})';
        if ($initial) {
            $patterns = [
                '/Ancien\s+solde[^0-9+-]{0,40}' . $amt . '/ui',
                '/Solde\s+(?:pr[eé]c[eé]dent|initial|d[eé]biteur|cr[eé]diteur)[^0-9+-]{0,40}' . $amt . '/ui',
                '/Solde\s+au\s+\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}[^0-9+-]{0,20}' . $amt . '/ui',
            ];
        } else {
            $patterns = [
                '/Nouveau\s+solde[^0-9+-]{0,40}' . $amt . '/ui',
                '/Solde\s+(?:final|de fin|au\s+\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4})[^0-9+-]{0,40}' . $amt . '/ui',
            ];
        }
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $a = $this->extractAmounts($m[1]);
                if ($a !== []) {
                    return $a[0]['value'];
                }
            }
        }
        return null;
    }

    private function frDateToIso(string $fr): ?string
    {
        $fr = str_replace('.', '/', $fr);
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $fr, $m)) {
            return null;
        }
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y >= 70 ? 1900 : 2000;
        }
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
}
