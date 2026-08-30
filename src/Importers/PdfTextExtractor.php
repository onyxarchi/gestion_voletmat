<?php
declare(strict_types=1);

namespace Voletmat\Importers;

use RuntimeException;

/**
 * Extraction de texte d’un PDF (relevé CIC).
 * Préfère pdftotext si disponible ; sinon flux PDF compressés (Tj/TJ).
 */
final class PdfTextExtractor
{
    public function extract(string $pdfPath): string
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException('Fichier PDF introuvable.');
        }

        $viaBin = $this->viaPdftotext($pdfPath);
        if ($viaBin !== null && trim($viaBin) !== '') {
            return $viaBin;
        }

        $viaPhp = $this->viaPhpStreams($pdfPath);
        if (trim($viaPhp) !== '') {
            return $viaPhp;
        }

        throw new RuntimeException(
            'Impossible d’extraire le texte du PDF. '
            . 'Installez pdftotext (poppler) sur le NAS, ou exportez le relevé en Excel (.xlsx) depuis CIC.'
        );
    }

    private function viaPdftotext(string $pdfPath): ?string
    {
        $bin = $this->findPdftotext();
        if ($bin === null) {
            return null;
        }
        $cmd = escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' -';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return null;
        }
        return implode("\n", $out);
    }

    private function findPdftotext(): ?string
    {
        foreach (['pdftotext', '/usr/local/bin/pdftotext', '/opt/bin/pdftotext', '/usr/bin/pdftotext'] as $c) {
            if ($c === 'pdftotext') {
                $which = [];
                @exec('command -v pdftotext 2>/dev/null', $which);
                if (!empty($which[0]) && is_executable($which[0])) {
                    return $which[0];
                }
                continue;
            }
            if (is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Lecture naïve des chaînes littérales dans les flux PDF (FlateDecode).
     * Suffisant pour beaucoup de relevés CIC texte ; pas d’OCR.
     */
    private function viaPhpStreams(string $pdfPath): string
    {
        $raw = @file_get_contents($pdfPath);
        if ($raw === false || $raw === '') {
            return '';
        }
        if (!str_starts_with($raw, '%PDF')) {
            throw new RuntimeException('Le fichier n’est pas un PDF valide.');
        }

        $chunks = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
            foreach ($m[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    // Parfois zlib header
                    $decoded = @gzuncompress("\x78\x9c" . $stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }
                $chunks[] = $this->stringsFromContent($decoded);
            }
        }
        // Aussi hors stream (PDF simples)
        $chunks[] = $this->stringsFromContent($raw);

        $text = implode("\n", array_filter($chunks));
        // Nettoyage espaces PDF
        $text = preg_replace('/\\\\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\\\\([()\\\\])/', '$1', $text) ?? $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }
        return iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: $text;
    }

    private function stringsFromContent(string $content): string
    {
        $parts = [];
        // (Hello) Tj  ou  [(Hel) -10 (lo)] TJ
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $content, $m)) {
            foreach ($m[0] as $tok) {
                if (preg_match('/^\((.*)\)\s*Tj$/s', $tok, $mm)) {
                    $parts[] = $this->unescapePdfString($mm[1]);
                }
            }
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $m)) {
            foreach ($m[1] as $arr) {
                $line = '';
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $arr, $sm)) {
                    foreach ($sm[0] as $s) {
                        $line .= $this->unescapePdfString(substr($s, 1, -1));
                    }
                }
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
        }
        return implode("\n", $parts);
    }

    private function unescapePdfString(string $s): string
    {
        $s = str_replace(['\\n', '\\r', '\\t', '\\b', '\\f'], ["\n", "\r", "\t", "\x08", "\x0c"], $s);
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn ($m) => chr(octdec($m[1])), $s) ?? $s;
        $s = preg_replace('/\\\\([()\\\\])/', '$1', $s) ?? $s;
        return $s;
    }
}
