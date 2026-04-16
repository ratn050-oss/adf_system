<?php
// Test harness for migrate-api.php
$_GET['action'] = 'check';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Simulate the API response
ob_start();
include 'migrate-api.php';
$response = ob_get_clean();

echo "=== RAW RESPONSE ===\n";
echo $response . "\n";
echo "\n=== RESPONSE LENGTH: " . strlen($response) . " bytes ===\n";

// Try to decode
$decoded = json_decode($response, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "✅ Valid JSON!\n";
    print_r($decoded);
} else {
    echo "❌ Invalid JSON: " . json_last_error_msg() . "\n";
    echo "First 500 chars:\n";
    echo substr($response, 0, 500);
}
