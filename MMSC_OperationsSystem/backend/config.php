<?php
/**
 * Local defaults + optional server-only config.local.php. Environment variables still take precedence.
 * Never commit config.local.php; it is ignored by .gitignore.
 */
$hasEnvConfig = getenv('MMSC_DB_HOST') !== false || getenv('MMSC_DB_NAME') !== false || getenv('MMSC_APP_ENV') !== false;
$base = [
    'db_host'            => '127.0.0.1',
    'db_port'            => 3306,
    'db_name'            => 'mmsc_db',
    'db_user'            => 'root',
    'db_pass'            => '',
    'timezone'           => 'Asia/Manila',
    'app_name'           => 'MMSC Operations',
    'company'            => 'Metro Mechanical Supply Corporation',
    'debug'              => true,
    'app_env'            => 'development',
    'require_https'      => false,
    'require_setup_key'  => false,
    'setup_key'          => '',
];
if ($hasEnvConfig) return [];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) $base = array_merge($base, $local);
}
return $base;
