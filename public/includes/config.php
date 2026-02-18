<?php
/**
 * PUBLIC WEBSITE - Configuration
 * Hotel Booking & Information Website
 */

// Prevent direct access
defined('PUBLIC_ACCESS') or define('PUBLIC_ACCESS', true);

// Enable output buffering
if (!ob_get_level()) {
    ob_start();
}

// ============================================
// TIMEZONE & LOCALE
// ============================================
date_default_timezone_set('Asia/Jakarta');
setlocale(LC_TIME, 'id_ID.UTF-8');

// ============================================
// PATH CONFIGURATION
// ============================================
// Fix paths using realpath to handle Windows/Linux properly
$publicPath = dirname(dirname(__FILE__)); // Go up 2 levels from includes/
$rootPath = dirname($publicPath); // Go up 1 level from public/

define('PUBLIC_PATH', $publicPath);
define('ROOT_PATH', $rootPath);
define('SYSTEM_PATH', ROOT_PATH);

// Get hosted domain to load correct business config
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// For development
$isLocalhost = (strpos($httpHost, 'localhost') !== false || strpos($httpHost, '127.0.0.1') !== false);

if ($isLocalhost) {
    // Support various port configurations (8081, etc)
    $hostUrl = $protocol . '://' . $httpHost . '/adf_system/public';
    $businessId = 'narayana-hotel'; // Default to narayana-hotel
} else {
    // Production: Detect by domain
    // Example: narayana.com -> narayana-hotel, benscafe.com -> bens-cafe
    $domain = explode('.', $httpHost)[0];
    $businessId = match($domain) {
        'narayana' => 'narayana-hotel',
        'bens' => 'bens-cafe',
        default => 'narayana-hotel'
    };
    $hostUrl = $protocol . '://' . $httpHost;
}

define('BASE_URL', $hostUrl);
define('BUSINESS_ID', $businessId);

// ============================================
// LOAD BUSINESS CONFIGURATION
// ============================================
$businessConfigPath = ROOT_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'businesses' . DIRECTORY_SEPARATOR . BUSINESS_ID . '.php';
if (!file_exists($businessConfigPath)) {
    die("ERROR: Business config not found at: " . $businessConfigPath);
}
$businessConfig = require $businessConfigPath;
define('BUSINESS_NAME', $businessConfig['name']);
define('BUSINESS_TYPE', $businessConfig['business_type']);
define('DB_NAME', $businessConfig['database']);

// Get appropriate database name
if ($isLocalhost) {
    // Local: adf_narayana_hotel
    $dbName = match(BUSINESS_ID) {
        'narayana-hotel' => 'adf_narayana_hotel',
        'bens-cafe' => 'adf_benscafe',
        default => 'adf_narayana_hotel'
    };
} else {
    // Production
    $dbName = 'adfb2574_' . DB_NAME;
}

define('DB_NAME_FINAL', $dbName);

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', $isLocalhost ? 'root' : 'adfb2574_adfsystem');
define('DB_PASS', $isLocalhost ? '' : '@Nnoc2025');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// WEBSITE SETTINGS (Will load from database)
// ============================================
$websiteSettings = [
    'hotel_name' => BUSINESS_NAME,
    'hotel_description' => 'Luxury Hotel in Karimunjawa',
    'phone' => '+62-821-4000-9999',
    'email' => 'booking@narayana.com',
    'address' => 'Karimunjawa Island, Central Java, Indonesia',
    'currency' => 'IDR',
    'currency_symbol' => 'Rp',
    'payment_methods' => ['transfer', 'qr', 'card'],
    'payment_gateway' => 'midtrans',
    'midtrans_client_key' => '',
    'midtrans_server_key' => '',
    'admin_email' => 'admin@narayana.com',
    'timezone' => 'Asia/Jakarta'
];

// ============================================
// HELPER FUNCTIONS
// ============================================
function getConfig($key, $default = null) {
    global $websiteSettings;
    return $websiteSettings[$key] ?? $default;
}

function baseUrl($path = '') {
    return BASE_URL . ($path ? '/' . ltrim($path, '/') : '');
}

function assetUrl($path) {
    return baseUrl('assets/' . ltrim($path, '/'));
}

function htmlize($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function redirect($url, $code = 302) {
    header('Location: ' . $url, true, $code);
    exit;
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function formatCurrency($amount) {
    $currency = getConfig('currency_symbol', 'Rp');
    $formatted = number_format($amount, 0, ',', '.');
    return $currency . ' ' . $formatted;
}

function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}
?>
