<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/Importers/CicExcelImporter.php';

date_default_timezone_set((string) app_config('timezone'));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Voletmat\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen('Voletmat\\')));
    $file = dirname(__DIR__) . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
