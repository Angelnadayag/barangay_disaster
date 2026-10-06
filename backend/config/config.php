<?php
// ============================================================================
// Intelligent Resource Allocation Recommender
// Backend Configuration & Constants
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

// Path Definitions
define('ROOT_PATH', dirname(dirname(__DIR__)));
define('BACKEND_PATH', ROOT_PATH . '/backend');
define('FUNCTIONS_PATH', ROOT_PATH . '/backend/functions');
define('FRONTEND_PATH', ROOT_PATH . '/frontend');
define('VIEWS_PATH', ROOT_PATH . '/views');
define('DATABASE_PATH', ROOT_PATH . '/database');

// Load local environment configuration (.env protected by .gitignore)
$envFilePath = ROOT_PATH . '/.env';
if (file_exists($envFilePath)) {
    $envLines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1], " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$k])) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}

// Helper to read environment variables (with fallback)
if (!function_exists('getEnvVar')) {
    function getEnvVar(string $key, string $default = ''): string {
        $val = getenv($key);
        if ($val !== false && $val !== '') return (string)$val;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    }
}

// Parse DATABASE_URL / TIDB_URL if provided as a single connection URI
$databaseUrl = getEnvVar('DATABASE_URL', getEnvVar('TIDB_URL', ''));
if (!empty($databaseUrl)) {
    $parsed = parse_url($databaseUrl);
    if ($parsed) {
        if (!empty($parsed['host']) && empty(getenv('DB_HOST'))) putenv('DB_HOST=' . $parsed['host']);
        if (!empty($parsed['port']) && empty(getenv('DB_PORT'))) putenv('DB_PORT=' . $parsed['port']);
        if (!empty($parsed['user']) && empty(getenv('DB_USER'))) putenv('DB_USER=' . urldecode($parsed['user']));
        if (isset($parsed['pass']) && getenv('DB_PASS') === false) putenv('DB_PASS=' . urldecode($parsed['pass']));
        if (!empty($parsed['path']) && empty(getenv('DB_NAME'))) putenv('DB_NAME=' . ltrim($parsed['path'], '/'));
    }
}

// Database Credentials (TiDB Cloud / Railway support, falls back to local XAMPP)
define('DB_HOST', getEnvVar('DB_HOST', getEnvVar('TIDB_HOST', 'localhost')));
define('DB_PORT', getEnvVar('DB_PORT', getEnvVar('TIDB_PORT', '3306')));
define('DB_USER', getEnvVar('DB_USER', getEnvVar('TIDB_USER', 'root')));
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : (isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : getEnvVar('TIDB_PASSWORD', '')));
define('DB_NAME', getEnvVar('DB_NAME', getEnvVar('TIDB_DATABASE', 'barangay_disaster_db')));

// SSL Configuration for Cloud Databases (TiDB Cloud requires TLS)
define('DB_SSL_CA', getEnvVar('DB_SSL_CA', ''));
$isSslRequired = (
    in_array(strtolower(getEnvVar('DB_SSL_ENABLE', '')), ['true', '1', 'yes']) ||
    strpos(DB_HOST, 'tidbcloud.com') !== false ||
    DB_PORT == '4000'
);
define('DB_SSL_ENABLE', $isSslRequired);

// Application Details
define('APP_NAME', 'ICDRRMO Resource Allocation Recommender');
define('APP_SUBTITLE', 'Barangay Disaster Preparedness Decision-Support System');

// SMS Gateway Settings (TextBee Free Android Gateway / Semaphore / Sandbox)
$smsProvider = getEnvVar('SMS_PROVIDER', 'textbee');
define('SMS_PROVIDER', $smsProvider);

$textbeeApiKey = getEnvVar('TEXTBEE_API_KEY', '');
$textbeeDeviceId = getEnvVar('TEXTBEE_DEVICE_ID', '');
define('TEXTBEE_API_KEY', $textbeeApiKey);
define('TEXTBEE_DEVICE_ID', $textbeeDeviceId);

$semaphoreApiKey = getEnvVar('SEMAPHORE_API_KEY', '78aa54957dfac0cb5dcc2facd92417fd');
$semaphoreSenderName = getEnvVar('SEMAPHORE_SENDER_NAME', 'SEMAPHORE');
define('SEMAPHORE_API_KEY', $semaphoreApiKey);
define('SEMAPHORE_SENDER_NAME', $semaphoreSenderName);

if ($smsProvider === 'textbee') {
    define('SMS_API_KEY', $textbeeApiKey);
    define('SMS_SENDER_NAME', 'TextBee');
} else {
    define('SMS_API_KEY', $semaphoreApiKey);
    define('SMS_SENDER_NAME', $semaphoreSenderName);
}

// Auto-detect BASE_URL relative to the web server's document root
if (!defined('BASE_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';
    $appRoot = str_replace('\\', '/', realpath(ROOT_PATH) ?: ROOT_PATH);
    $baseUrl = '';
    if (!empty($docRoot) && !empty($appRoot) && stripos($appRoot, $docRoot) === 0) {
        $baseUrl = substr($appRoot, strlen($docRoot));
    }
    define('BASE_URL', rtrim($baseUrl, '/'));
}

// JSON Helper (Fallback & AJAX Support)
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
