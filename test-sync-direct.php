<?php
/**
 * TEST CASHBOOK SYNC - langsung test tanpa reservasi
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Cashbook Sync</h1><pre>";

session_start();
$_SESSION['business_id'] = 1; // Hotel
$_SESSION['user_id'] = 1;

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

echo "1. Session: business_id=" . $_SESSION['business_id'] . ", user_id=" . $_SESSION['user_id'] . "\n\n";

// Check if CashbookHelper exists
$helperFile = __DIR__ . '/includes/CashbookHelper.php';
echo "2. CashbookHelper file exists: " . (file_exists($helperFile) ? "YES" : "NO") . "\n";

if (!file_exists($helperFile)) {
    die("CashbookHelper.php TIDAK ADA di hosting! Upload dulu.");
}

require_once $helperFile;

echo "3. CashbookHelper loaded\n\n";

$db = Database::getInstance();

echo "4. Database: " . Database::getCurrentDatabase() . "\n\n";

// Test sync
echo "5. Testing sync...\n";

try {
    $helper = new CashbookHelper($db, $_SESSION['business_id'], $_SESSION['user_id']);
    
    $result = $helper->syncPaymentToCashbook([
        'payment_id' => null,
        'booking_id' => 999,
        'amount' => 100000,
        'payment_method' => 'cash',
        'guest_name' => 'TEST GUEST',
        'booking_code' => 'TEST-' . date('His'),
        'room_number' => '101',
        'booking_source' => 'direct',
        'final_price' => 100000,
        'total_paid' => 100000,
        'is_new_reservation' => true
    ]);
    
    echo "\nResult:\n";
    print_r($result);
    
    if ($result['success']) {
        echo "\n\n✅ SUKSES! Cek Buku Kas sekarang.\n";
    } else {
        echo "\n\n❌ GAGAL: " . $result['message'] . "\n";
    }
    
} catch (Throwable $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString();
}

echo "\n</pre>";
