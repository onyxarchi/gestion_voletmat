<?php
declare(strict_types=1);

/**
 * Configuration de base — Vol&Mat Gestion.
 * Ne pas y mettre de secrets. Copier config.local.php.example vers config.local.php.
 */

return [
    'app_name' => 'Vol&Mat Gestion',
    'app_env' => 'local',
    'timezone' => 'Europe/Paris',
    'locale' => 'fr_FR',

    // SQLite en local (un fichier = sauvegarde facile).
    // Sur le NAS (Web Station) : chemin local du volume, OK.
    // Depuis un Mac monté en SMB : SQLite plante souvent → définir
    // VOLETMAT_SQLITE_PATH vers un fichier local, ou config.local.php.
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => getenv('VOLETMAT_SQLITE_PATH')
            ?: (dirname(__DIR__) . '/storage/data/voletmat_gestion.sqlite'),
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'dbname' => 'voletmat_gestion',
            'user' => 'voletmat_gestion',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],

    'session_name' => 'voletmat_gestion_sess',
    'upload_max_bytes' => 8 * 1024 * 1024,
    'storage_uploads' => dirname(__DIR__) . '/storage/uploads',
    'storage_backups' => dirname(__DIR__) . '/storage/backups',
];
