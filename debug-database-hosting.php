<?php
/**
 * DEBUG DATABASE CONNECTIONS
 * Run this on HOSTING to see which databases are being used
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Database Debug for Hosting</h1>";
echo "<pre>";

// Check environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
echo "Environment: " . ($isProduction ? "PRODUCTION/HOSTING" : "LOCAL") . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "\n\n";

// Start session to check session values
session_start();

echo "=== SESSION VALUES ===\n";
echo "business_id: " . ($_SESSION['business_id'] ?? 'NOT SET') . "\n";
echo "active_business_id: " . ($_SESSION['active_business_id'] ?? 'NOT SET') . "\n";
echo "user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n\n";

define('APP_ACCESS', true);

// Load config without connecting
echo "=== CONFIG CONSTANTS ===\n";

// Database mapping for hosting
$dbMapping = [
    'adf_system' => 'adfb2574_adf',
    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
    'adf_benscafe' => 'adfb2574_Adf_Bens'
];

echo "Database Mapping:\n";
foreach ($dbMapping as $local => $prod) {
    echo "  $local => $prod\n";
}
echo "\n";

require_once 'config/config.php';

echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "MASTER_DB_NAME: " . MASTER_DB_NAME . "\n";
echo "ACTIVE_BUSINESS_ID: " . ACTIVE_BUSINESS_ID . "\n";
echo "BUSINESS_NAME: " . BUSINESS_NAME . "\n";
echo "BUSINESS_TYPE: " . BUSINESS_TYPE . "\n\n";

require_once 'config/database.php';

echo "=== DATABASE CONNECTIONS ===\n";

try {
    $db = Database::getInstance();
    $currentDb = Database::getCurrentDatabase();
    echo "Business DB Connected: " . $currentDb . "\n";
    
    // Verify which database we're in
    $dbCheck = $db->fetchOne("SELECT DATABASE() as db");
    echo "Actual DB (from query): " . $dbCheck['db'] . "\n";
    
    // Check if this DB has booking_payments table
    $tables = $db->fetchAll("SHOW TABLES LIKE 'booking_payments'");
    echo "Has booking_payments table: " . (!empty($tables) ? "YES" : "NO") . "\n";
    
    if (!empty($tables)) {
        $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM booking_payments");
        echo "Total booking_payments records: " . $count['cnt'] . "\n";
    }
    
    // Check cash_book table
    $cashTables = $db->fetchAll("SHOW TABLES LIKE 'cash_book'");
    echo "Has cash_book table: " . (!empty($cashTables) ? "YES" : "NO") . "\n";
    
    if (!empty($cashTables)) {
        $cashCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM cash_book");
        echo "Total cash_book records: " . $cashCount['cnt'] . "\n";
    }
    
} catch (Throwable $e) {
    echo "Business DB Error: " . $e->getMessage() . "\n";
}

echo "\n=== MASTER DATABASE ===\n";

try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "Master DB Connected: " . MASTER_DB_NAME . "\n";
    
    // Verify
    $masterCheck = $masterDb->query("SELECT DATABASE() as db")->fetch(PDO::FETCH_ASSOC);
    echo "Actual Master DB: " . $masterCheck['db'] . "\n";
    
    // Check businesses table
    $businesses = $masterDb->query("SELECT id, business_name FROM businesses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nBusinesses in master DB:\n";
    foreach ($businesses as $b) {
        echo "  ID {$b['id']}: {$b['business_name']}\n";
    }
    
    // Check cash_accounts table
    $accounts = $masterDb->query("SELECT id, business_id, account_name, account_type, current_balance FROM cash_accounts ORDER BY business_id, id")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nCash Accounts in master DB:\n";
    
    $currentBusinessId = null;
    foreach ($accounts as $a) {
        if ($currentBusinessId !== $a['business_id']) {
            $currentBusinessId = $a['business_id'];
            echo "\n  === BUSINESS ID {$a['business_id']} ===\n";
        }
        $balance = number_format($a['current_balance'] ?? 0, 0, ',', '.');
        echo "    [{$a['account_type']}] {$a['account_name']} (ID: {$a['id']}) - Balance: Rp {$balance}\n";
    }
    
    // Show what the active session would use
    $sessionBizId = $_SESSION['business_id'] ?? 'NOT SET';
    echo "\n⚠️ SESSION business_id = {$sessionBizId}\n";
    echo "This means CashbookHelper will look for cash_accounts where business_id = {$sessionBizId}\n";
    
    if (is_numeric($sessionBizId)) {
        $sessionAccounts = $masterDb->query("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = {$sessionBizId}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($sessionAccounts)) {
            echo "⚠️ NO CASH ACCOUNTS FOUND for business_id={$sessionBizId}!\n";
            echo "This could be why cashbook sync fails!\n";
        } else {
            echo "Found " . count($sessionAccounts) . " account(s) for this business\n";
        }
    }
    
} catch (Throwable $e) {
    echo "Master DB Error: " . $e->getMessage() . "\n";
}

echo "\n=== BUSINESS CONFIG FILES ===\n";

$businessFiles = glob(__DIR__ . '/config/businesses/*.php');
foreach ($businessFiles as $file) {
    $config = require $file;
    $filename = basename($file);
    echo "\n$filename:\n";
    echo "  Name: " . ($config['name'] ?? 'N/A') . "\n";
    echo "  Database (local): " . ($config['database'] ?? 'N/A') . "\n";
    
    // Show what it maps to in production
    if (isset($config['database']) && isset($dbMapping[$config['database']])) {
        echo "  Database (hosting): " . $dbMapping[$config['database']] . "\n";
    }
}

echo "\n=== RECOMMENDATIONS ===\n";

// Check if session business_id matches the active business
if (isset($_SESSION['active_business_id'])) {
    $activeId = $_SESSION['active_business_id'];
    
    if ($activeId === 'narayana-hotel') {
        $expectedBusinessId = 1; // Assuming hotel is ID 1
        echo "Active Business: Hotel (narayana-hotel)\n";
        echo "Expected business_id in session: $expectedBusinessId\n";
        echo "Actual business_id in session: " . ($_SESSION['business_id'] ?? 'NOT SET') . "\n";
        
        if (($_SESSION['business_id'] ?? 0) != $expectedBusinessId) {
            echo "⚠️ WARNING: business_id mismatch! Cash accounts might be wrong.\n";
        }
    } elseif ($activeId === 'bens-cafe') {
        $expectedBusinessId = 2; // Assuming cafe is ID 2
        echo "Active Business: Ben's Cafe (bens-cafe)\n";
        echo "Expected business_id in session: $expectedBusinessId\n";
        echo "Actual business_id in session: " . ($_SESSION['business_id'] ?? 'NOT SET') . "\n";
    }
}

echo "\n</pre>";
echo "<p><a href='fix-cashbook-sync.php'>→ Fix Cashbook Sync</a> | <a href='modules/cashbook/index.php'>→ Buku Kas</a></p>";
