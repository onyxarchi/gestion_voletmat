#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Lance l’import Excel N4/N5 vers SQLite.
 * Prérequis : Python 3 + openpyxl (venv projet ou système).
 */
$root = dirname(__DIR__);
$db = getenv('VOLETMAT_SQLITE_PATH') ?: (getenv('HOME') . '/voletmat_gestion.sqlite');
putenv('VOLETMAT_SQLITE_PATH=' . $db);

$candidates = [
    '/tmp/voletmat_xlsm_analysis/venv/bin/python',
    $root . '/.venv/bin/python',
    'python3',
];
$python = null;
foreach ($candidates as $c) {
    if ($c === 'python3') {
        $python = 'python3';
        break;
    }
    if (is_executable($c)) {
        $python = $c;
        break;
    }
}
if ($python === null) {
    fwrite(STDERR, "Python introuvable.\n");
    exit(1);
}

$script = $root . '/scripts/import_classeurs.py';
passthru(escapeshellarg($python) . ' ' . escapeshellarg($script), $code);
exit($code);
