<?php
/**
 * ADF SYSTEM - Multi-Business Management Platform
 * Configuration File
 */

// START: Buffer & Session MUST be first before any output
if (!ob_get_level()) {
    ob_start();
}

// Session configuration BEFORE any requires
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'NARAYANA_SESSION');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600 * 8);

// Initialize session BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Prevent direct access
defined('APP_ACCESS') or define('APP_ACCESS', true);

// ============================================
// APPLICATION SETTINGS
// ============================================
if (!defined('APP_NAME')) define('APP_NAME', 'ADF System - Multi-Business Management');
if (!defined('APP_VERSION')) define('APP_VERSION', '2.0.0');
if (!defined('APP_YEAR')) define('APP_YEAR', '2026');
if (!defined('DEVELOPER_NAME')) define('DEVELOPER_NAME', 'Ariefsystemdesign.net');
if (!defined('DEVELOPER_LOGO')) define('DEVELOPER_LOGO', 'assets/img/developer-logo.png');

// ============================================
// DATABASE CONFIGURATION
// ============================================
// Local config (override for production in separate file if needed)
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction) {
    // Production (Hosting) - uses adf_system database prefixed
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'adfb2574_adf');
    if (!defined('DB_USER')) define('DB_USER', 'adfb2574_adfsystem');
    if (!defined('DB_PASS')) define('DB_PASS', '@Nnoc2025');
} else {
    // Local development - uses adf_system as master database
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'adf_system');
    if (!defined('DB_USER')) define('DB_USER', 'root');
    if (!defined('DB_PASS')) define('DB_PASS', '');
}
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// ============================================
// PATH CONFIGURATION
// ============================================
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(__FILE__)));

// Handle both web and CLI environment
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$port = $_SERVER['SERVER_PORT'] ?? '80';
$portSuffix = ($port != '80' && $port != '443') ? ':' . $port : '';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// Only define BASE_URL in web environment
if (!defined('BASE_URL')) {
    if (php_sapi_name() !== 'cli') {
        define('BASE_URL', $protocol . '://' . $host . '/adf_system');
    } else {
        // For CLI, just use a placeholder
        define('BASE_URL', 'http://localhost/adf_system');
    }
}

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('Asia/Jakarta');

// ============================================
// ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// CURRENCY FORMAT
// ============================================
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'Rp');
if (!defined('CURRENCY_DECIMAL')) define('CURRENCY_DECIMAL', 0);

// ============================================
// DATABASE NAME HELPER (Production vs Local)
// ============================================
/**
 * Get correct database name for production or local
 * @param string $localDbName - Local database name (e.g., 'adf_system', 'adf_narayana_hotel')
 * @return string - Correct database name for current environment
 */
function getDbName($localDbName) {
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    
    if (!$isProduction) {
        return $localDbName;
    }
    
    // Production database mapping
    $dbMapping = [
        'adf_system' => 'adfb2574_adf',
        'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
        'adf_benscafe' => 'adfb2574_Adf_Bens'
    ];
    
    return $dbMapping[$localDbName] ?? $localDbName;
}

// Also make it available as constant for master DB
if (!defined('MASTER_DB_NAME')) define('MASTER_DB_NAME', getDbName('adf_system'));

// ============================================
// PAGINATION
// ============================================
if (!defined('RECORDS_PER_PAGE')) define('RECORDS_PER_PAGE', 25);

// ============================================
// DATE FORMAT
// ============================================
if (!defined('DATE_FORMAT')) define('DATE_FORMAT', 'd/m/Y');
if (!defined('DATETIME_FORMAT')) define('DATETIME_FORMAT', 'd/m/Y H:i');
if (!defined('TIME_FORMAT')) define('TIME_FORMAT', 'H:i');

// ============================================
// MULTI-BUSINESS CONFIGURATION
// ============================================
require_once __DIR__ . '/../includes/business_helper.php';

$activeBusinessId = getActiveBusinessId();
$BUSINESS_CONFIG = getActiveBusinessConfig();

define('ACTIVE_BUSINESS_ID', $activeBusinessId);
define('BUSINESS_NAME', $BUSINESS_CONFIG['name']);
define('BUSINESS_TYPE', $BUSINESS_CONFIG['business_type']);
define('BUSINESS_ICON', $BUSINESS_CONFIG['theme']['icon']);
define('BUSINESS_COLOR', $BUSINESS_CONFIG['theme']['color_primary']);

// ============================================
// LANGUAGE CONFIGURATION
// ============================================
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/language.php';