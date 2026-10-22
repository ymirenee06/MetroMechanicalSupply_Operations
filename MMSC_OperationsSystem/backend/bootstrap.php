<?php
// Loads configuration, hardens the HTTP session, then loads the app.

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

$asBool = static function ($value, bool $default = false): bool {
    if ($value === null) return $default;
    $v = strtolower(trim((string)$value));
    if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
    if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;
    return $default;
};

$appEnv = (string)$env('MMSC_APP_ENV', 'development');

$config = [
    'db_host'            => (string)$env('MMSC_DB_HOST', '127.0.0.1'),
    'db_port'            => (int)$env('MMSC_DB_PORT', 3306),
    'db_name'            => (string)$env('MMSC_DB_NAME', 'mmsc_db'),
    'db_user'            => (string)$env('MMSC_DB_USER', 'root'),
    'db_pass'            => (string)$env('MMSC_DB_PASS', ''),
    'timezone'           => (string)$env('MMSC_TIMEZONE', 'Asia/Manila'),
    'app_name'           => (string)$env('MMSC_APP_NAME', 'MMSC Operations'),
    'company'            => (string)$env('MMSC_COMPANY', 'Your Company Name'),
    'debug'              => $asBool($env('MMSC_DEBUG', null), $appEnv !== 'production'),
    'app_env'            => $appEnv,
    'require_https'      => $asBool($env('MMSC_REQUIRE_HTTPS', null), $appEnv === 'production'),
    'require_setup_key'  => $asBool($env('MMSC_REQUIRE_SETUP_KEY', null), $appEnv === 'production'),
    'setup_key'          => (string)$env('MMSC_SETUP_KEY', ''),
];

// An untracked local config.php may override environment defaults for XAMPP/dev.
$localConfig = __DIR__ . '/config.php';
if (is_file($localConfig)) {
    $local = require $localConfig;
    if (is_array($local)) $config = array_merge($config, $local);
}

$GLOBALS['CONFIG'] = $config;
date_default_timezone_set($GLOBALS['CONFIG']['timezone']);

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function enforce_production_http(): void
{
    if (PHP_SAPI === 'cli') return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if (!empty($GLOBALS['CONFIG']['require_https']) && !request_is_https()) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host !== '') {
            header('Location: https://' . $host . $uri, true, 302);
            exit;
        }
    }
}

enforce_production_http();

$secureCookie = request_is_https();
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $secureCookie,
]);
session_name('MMSCSESSID');
session_start();
// Expire unattended authenticated sessions after 60 minutes. The server-side timestamp
// is used; a browser-provided value cannot extend the session.
$idleLimit = 3600;
if (isset($_SESSION['user_id'], $_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idleLimit) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

require __DIR__ . '/db.php';
require __DIR__ . '/errors.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/services/InventoryService.php';
require __DIR__ . '/services/JobService.php';
require __DIR__ . '/services/ReleaseService.php';
require __DIR__ . '/services/SalesService.php';
require __DIR__ . '/services/SalesWorkflowService.php';
require __DIR__ . '/services/AdminService.php';
require __DIR__ . '/services/CustomerService.php';
require __DIR__ . '/services/ExportService.php';
require __DIR__ . '/services/SummaryService.php';
