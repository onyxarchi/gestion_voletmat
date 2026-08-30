<?php
declare(strict_types=1);

/**
 * Test rapide de l’import CIC sans serveur web.
 * Usage : php scripts/test_cic_import.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Voletmat\Importers\CicExcelImporter;

$file = dirname(__DIR__) . '/aides/comptes.xlsx';
if (!is_file($file)) {
    fwrite(STDERR, "Fichier aides/comptes.xlsx introuvable.\n");
    exit(1);
}

$r = (new CicExcelImporter())->parse($file);
echo "Compte : {$r['compte']}\n";
echo "Lignes : " . count($r['lignes']) . "\n";
$ok = 0;
$bad = 0;
foreach ($r['lignes'] as $l) {
    if ($l['statut'] === 'ok') {
        $ok++;
    } else {
        $bad++;
    }
}
echo "OK={$ok} incertains={$bad}\n";
echo "Première ligne :\n";
print_r($r['lignes'][0] ?? null);
