<?php
/**
 * DEBUG: Show BASE_URL and endpoints
 */

define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<h1>Debug: BASE_URL & Endpoints</h1>\n";
echo "<pre>\n";

echo "Current Configuration:\n";
echo "- BASE_URL: " . BASE_URL . "\n";
echo "- DB_HOST: " . DB_HOST . "\n";
echo "- DB_NAME (constant): " . DB_NAME . "\n";
echo "- DB_USER: " . DB_USER . "\n";

echo "\nServer Info:\n";
echo "- HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "- SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'NOT SET') . "\n";
echo "- REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";

echo "\nConstructed Endpoints:\n";
$endpoints = [
    'Get Bills' => BASE_URL . '/api/get-monthly-bills-simple.php',
    'Add Bill' => BASE_URL . '/api/add-monthly-bill.php',
    'Pay Bill' => BASE_URL . '/api/pay-monthly-bill.php',
];

foreach ($endpoints as $name => $url) {
    echo "- $name: $url\n";
}

echo "\nTest each file exists:\n";
$baseDir = dirname(__FILE__);
$apiFiles = [
    'get-monthly-bills-simple.php',
    'add-monthly-bill.php',
    'pay-monthly-bill.php',
];

foreach ($apiFiles as $file) {
    $fullPath = $baseDir . '/api/' . $file;
    $exists = file_exists($fullPath) ? "✓ EXISTS" : "✗ NOT FOUND";
    echo "- /api/$file: $exists\n";
}

echo "\n</pre>\n";
?>
