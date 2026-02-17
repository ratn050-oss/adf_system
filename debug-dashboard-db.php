<?php
/**
 * Debug Dashboard Database Connection
 * Check which database is being used for cashbook data
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

echo "<h2>Dashboard Database Debug</h2>";
echo "<pre>";

// Session info
echo "=== SESSION INFO ===\n";
echo "active_business_id: " . ($_SESSION['active_business_id'] ?? 'NOT SET') . "\n";
echo "business_id: " . ($_SESSION['business_id'] ?? 'NOT SET') . "\n";

// Constants
echo "\n=== CONSTANTS ===\n";
echo "ACTIVE_BUSINESS_ID: " . (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'NOT DEFINED') . "\n";
echo "BUSINESS_NAME: " . (defined('BUSINESS_NAME') ? BUSINESS_NAME : 'NOT DEFINED') . "\n";
echo "BUSINESS_TYPE: " . (defined('BUSINESS_TYPE') ? BUSINESS_TYPE : 'NOT DEFINED') . "\n";
echo "DB_NAME (master): " . DB_NAME . "\n";

// Database instance
echo "\n=== DATABASE CONNECTION ===\n";
$db = Database::getInstance();
echo "Current Database: " . Database::getCurrentDatabase() . "\n";

// Check cash_book data
echo "\n=== CASH_BOOK COUNT ===\n";
try {
    $result = $db->fetchOne("SELECT COUNT(*) as total FROM cash_book");
    echo "Total records in cash_book: " . $result['total'] . "\n";
    
    // Get sample data
    $sample = $db->fetchAll("SELECT id, description, amount, transaction_date FROM cash_book LIMIT 5");
    echo "\nSample cash_book data:\n";
    foreach ($sample as $row) {
        echo "  - ID: {$row['id']}, Desc: {$row['description']}, Amount: {$row['amount']}, Date: {$row['transaction_date']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Compare with expected
echo "\n=== EXPECTED DATABASE ===\n";
$businessConfig = require 'config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
echo "Business config database: " . $businessConfig['database'] . "\n";

// Check if matches
if ($businessConfig['database'] !== Database::getCurrentDatabase()) {
    echo "\n*** WARNING: Database mismatch! ***\n";
    echo "Expected: " . $businessConfig['database'] . "\n";
    echo "Actual: " . Database::getCurrentDatabase() . "\n";
} else {
    echo "\n*** OK: Database matches business config ***\n";
}

echo "</pre>";
