<?php
declare(strict_types=1);

namespace Voletmat;

use PDO;
use Voletmat\Importers\CicPdfImporter;

/**
 * Pré-remplit le TRI banque à partir des opérations déjà classées
 * (libellés récurrents : prélèvements, rémunérations, CB habituelles…).
 */
final class BanqueTriSuggester
{
    /** Mots trop génériques pour servir de signature. */
    private const STOP = [
        'PRLV', 'SEPA', 'VIR', 'INST', 'PAIEMENT', 'CB', 'PSC', 'VIREMENT', 'DE', 'DU',
        'LA', 'LE', 'LES', 'DES', 'ET', 'OU', 'MME', 'MR', 'M', 'MONSIEUR',
        'MADAME', 'CARTE', 'FACT', 'FACTURE', 'FACTURES', 'DONT', 'EUR', 'USD',
        'PARIS', 'ICS', 'RUM',
    ];

    /**
     * Règles métier stables (créanciers connus).
     * @var list<array{0:string,1:string}>
     */
    private const RULES = [
        ['/\bBOUYGUES\b/iu', 'TEL'],
        ['/\bFREE\s+TELECOM\b/iu', 'NET'],
        ['/\bFREE\s+MOBILE\b|\bFREE\s+MOBILE\b/iu', 'TEL'],
        ['/\bOPENAI\b/iu', 'LOGICIEL'],
        ['/\bGPJ\b/iu', 'PJ'],
        ['/\bGROUPAMA\s+PROTECTION\b/iu', 'PJ'],
        ['/\bDGFIP\b.*\bTVA/iu', 'TVA'],
        ['/\bDIR\.?\s*GENE\.?\s*DES\s+FINANCES\b.*\bTVA/iu', 'TVA'],
        ['/\bDGFIP\b.*\bIS[- ]/iu', 'IS'],
        ['/\bDGFIP\b.*\bRCM\b/iu', 'CFE'],
        ['/\bSWISSLIFE\b/iu', 'PREV'],
        ['/\bURSSAF\b/iu', 'URSSAF'],
        ['/\bSMABTP\b/iu', 'SMABTP'],
        ['/\bCOMPAGNIE\s+FIDUCIAIRE\b|\bVISEEON\b/iu', 'COMPTA'],
        ['/\bGGVIE\b|\bPERIN\b/iu', 'PER'],
        ['/\bREMUNERATION\b/iu', 'REM'],
        ['/\bAPPLE\s+COM\b/iu', 'INFORMATIQUE'],
        ['/\bAMAZON\b/iu', 'FOURN'],
    ];

    /** @var array<string, array{code:string, n:int}>|null */
    private ?array $exact = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Code TRI le plus fréquent pour un libellé proche des opérations déjà classées.
     */
    public function suggest(?string $libelle, ?float $debit = null, ?float $credit = null): ?string
    {
        $lib = trim((string) $libelle);
        if ($lib === '') {
            return null;
        }

        foreach (self::RULES as [$re, $code]) {
            if (preg_match($re, $lib)) {
                return $code;
            }
        }

        // Crédit entrant type virement client → VENTE
        if ($credit !== null && $credit > 0.005 && ($debit === null || abs($debit) < 0.005)) {
            if (preg_match('/\b(VIR INST|VIR SEPA|VIREMENT DE)\b/iu', $lib)
                && !preg_match('/\b(REMUNERATION|PRLV|DGFIP|URSSAF)\b/iu', $lib)) {
                return 'VENTE';
            }
        }

        $this->ensureIndex();
        $sig = self::signature($lib);
        if ($sig === '') {
            return null;
        }

        if (isset($this->exact[$sig])) {
            return $this->exact[$sig]['code'];
        }

        $tokens = array_values(array_unique(preg_split('/\s+/u', $sig, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $bestCode = null;
        $bestScore = 0;
        $sigLen = mb_strlen($sig, 'UTF-8');

        foreach ($this->exact as $histSig => $info) {
            if ($info['n'] < 1) {
                continue;
            }
            $score = 0;
            if (str_starts_with($sig, $histSig) || str_starts_with($histSig, $sig)) {
                $score = min($sigLen, mb_strlen($histSig, 'UTF-8')) * 10 + $info['n'];
            } else {
                $common = self::commonPrefixLen($sig, $histSig);
                if ($common >= 12) {
                    $score = $common * 8 + $info['n'];
                }
            }

            $ht = array_values(array_unique(preg_split('/\s+/u', $histSig, -1, PREG_SPLIT_NO_EMPTY) ?: []));
            if ($tokens !== [] && $ht !== []) {
                $inter = count(array_intersect($tokens, $ht));
                $need = min(2, count($ht));
                if ($inter >= $need) {
                    $score = max($score, $inter * 40 + $info['n']);
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCode = $info['code'];
            }
        }

        return $bestScore >= 50 ? $bestCode : null;
    }

    /**
     * Applique les suggestions aux opérations sans TRI.
     * @return int Nombre de lignes mises à jour
     */
    public function appliquerSurVides(?int $exerciceId = null): int
    {
        $sql = 'SELECT id, libelle, debit, credit FROM operations_bancaires
                WHERE categorie_code IS NULL';
        $params = [];
        if ($exerciceId !== null) {
            $sql .= ' AND exercice_id = ?';
            $params[] = $exerciceId;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        if (!$rows) {
            return 0;
        }

        $upd = $this->pdo->prepare(
            'UPDATE operations_bancaires SET categorie_code = ? WHERE id = ? AND categorie_code IS NULL'
        );
        $n = 0;
        foreach ($rows as $r) {
            $debit = $r['debit'] !== null ? (float) $r['debit'] : null;
            $credit = $r['credit'] !== null ? (float) $r['credit'] : null;
            $code = $this->suggest((string) $r['libelle'], $debit, $credit);
            if ($code === null) {
                continue;
            }
            $upd->execute([$code, (int) $r['id']]);
            if ($upd->rowCount() > 0) {
                $n++;
            }
        }
        if ($n > 0) {
            $this->exact = null;
        }
        return $n;
    }

    /**
     * Ménage : libellés (pieds de page / refs techniques) + TRI manquants.
     * @return array{libelles:int, tri:int}
     */
    public function menage(?int $exerciceId = null): array
    {
        $sql = 'SELECT id, libelle FROM operations_bancaires WHERE 1=1';
        $params = [];
        if ($exerciceId !== null) {
            $sql .= ' AND exercice_id = ?';
            $params[] = $exerciceId;
        }
        // Priorité : libellés longs / PRLV bruyants
        $sql .= ' AND (length(libelle) > 60 OR libelle LIKE \'PRLV%\')';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $upd = $this->pdo->prepare('UPDATE operations_bancaires SET libelle = ? WHERE id = ?');
        $nLib = 0;
        while ($r = $st->fetch()) {
            $clean = CicPdfImporter::sanitizeLibelle((string) $r['libelle']);
            if ($clean === '' || $clean === (string) $r['libelle']) {
                continue;
            }
            try {
                $upd->execute([$clean, (int) $r['id']]);
                $nLib++;
            } catch (\Throwable) {
                // NAS / SQLite parfois en I/O error : on continue les autres lignes
            }
        }
        $this->exact = null;
        $nTri = $this->appliquerSurVides($exerciceId);
        return ['libelles' => $nLib, 'tri' => $nTri];
    }

    /**
     * Signature stable d’un libellé (sans dates, refs, montants).
     */
    public static function signature(string $libelle): string
    {
        $s = mb_strtoupper(trim($libelle), 'UTF-8');
        $s = strtr($s, ['’' => "'", '´' => "'"]);
        $s = preg_replace('/\bUN\.\d[\d.]*/u', ' ', $s) ?? $s;
        $s = preg_replace('/\b\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\b(?=[A-Z0-9]*\d)[A-Z0-9]{10,}\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\bCARTE\s+\d+\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\bF-?\d{2,4}[- ]?\d{0,4}[- ]?\d*\b/ui', ' ', $s) ?? $s;
        $s = preg_replace('/\b\d+([.,]\d+)?\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^A-ZÀ-Ü\/\-+ ]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);

        $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        foreach ($parts as $p) {
            if (in_array($p, self::STOP, true)) {
                continue;
            }
            if (mb_strlen($p, 'UTF-8') < 2) {
                continue;
            }
            $kept[] = $p;
        }
        return $kept !== [] ? implode(' ', $kept) : $s;
    }

    private function ensureIndex(): void
    {
        if ($this->exact !== null) {
            return;
        }
        $rows = $this->pdo->query(
            'SELECT libelle, categorie_code FROM operations_bancaires
             WHERE categorie_code IS NOT NULL AND TRIM(categorie_code) != \'\''
        )->fetchAll();

        /** @var array<string, array<string, int>> $votes */
        $votes = [];
        foreach ($rows as $r) {
            $sig = self::signature((string) $r['libelle']);
            if ($sig === '') {
                continue;
            }
            $code = (string) $r['categorie_code'];
            $votes[$sig][$code] = ($votes[$sig][$code] ?? 0) + 1;
        }

        $this->exact = [];
        foreach ($votes as $sig => $byCode) {
            arsort($byCode);
            $top = (string) array_key_first($byCode);
            $this->exact[$sig] = ['code' => $top, 'n' => (int) $byCode[$top]];
        }
    }

    private static function commonPrefixLen(string $a, string $b): int
    {
        $max = min(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        $i = 0;
        while ($i < $max && mb_substr($a, $i, 1, 'UTF-8') === mb_substr($b, $i, 1, 'UTF-8')) {
            $i++;
        }
        return $i;
    }
}
