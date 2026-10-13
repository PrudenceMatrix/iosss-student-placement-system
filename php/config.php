<?php

/*
 * Class work assignment configuration for local demonstration.
 * These defaults are intended for the assignment setup, not production use.
 */

$envFile = dirname(__DIR__) . '/.env';
$envConfig = [];
if (is_file($envFile)) {
    $parsedConfig = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if ($parsedConfig === false) {
        error_log('IOSSS could not parse the project .env file.');
        http_response_code(500);
        exit('The system configuration file could not be read. Please contact the administrator.');
    }
    $envConfig = $parsedConfig;
}

$dbSetting = static function (string $name, string $default) use ($envConfig): string {
    $environmentValue = getenv('IOSSS_DB_' . $name);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    return (string)($envConfig['IOSSS_DB_' . $name] ?? $default);
};

define('DB_HOST', $dbSetting('HOST', 'localhost'));
define('DB_USER', $dbSetting('USER', 'root'));
define('DB_PASS', $dbSetting('PASS', ''));
define('DB_NAME', $dbSetting('NAME', 'iosss_db'));
