<?php
/**
 * Check existing database structure
 */

require_once 'config/config.php';
require_once 'config/database.php';

echo "========================================\n";
echo "ADF SYSTEM - DATABASE STRUCTURE CHECK\n";
echo "========================================\n\n";

// Check adf_system (master) database
echo "1. ADF_SYSTEM (Master Database):\n";
echo "   Database: " . DB_NAME . "\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get list of tables
    $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "   Total Tables: " . count($tables) . "\n";
    echo "   Tables:\n";
    foreach ($tables as $table) {
        echo "      - $table\n";
    }
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Check adf_narayana_hotel database
echo "2. ADF_NARAYANA_HOTEL (Business Database):\n";
echo "   Database: adf_narayana_hotel\n";

try {
    $dbHotel = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_narayana_hotel;charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $tables = $dbHotel->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "   Total Tables: " . count($tables) . "\n";
    echo "   Tables:\n";
    foreach ($tables as $table) {
        echo "      - $table\n";
    }
    
    // Check specific tables we care about
    echo "\n   Checking specific tables:\n";
    
    $checkTables = ['cash_accounts', 'cash_account_transactions', 'cashbook', 'cash_balance'];
    foreach ($checkTables as $tbl) {
        $exists = $dbHotel->query("SHOW TABLES LIKE '$tbl'")->fetch();
        if ($exists) {
            echo "      ✓ $tbl EXISTS\n";
            // Get column info
            $cols = $dbHotel->query("DESCRIBE $tbl")->fetchAll();
            echo "        Columns: " . implode(', ', array_column($cols, 'Field')) . "\n";
        } else {
            echo "      ✗ $tbl NOT FOUND\n";
        }
    }
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Check adf_benscafe database
echo "3. ADF_BENSCAFE (Business Database):\n";
echo "   Database: adf_benscafe\n";

try {
    $dbCafe = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_benscafe;charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $tables = $dbCafe->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "   Total Tables: " . count($tables) . "\n";
    echo "   Tables:\n";
    foreach ($tables as $table) {
        echo "      - $table\n";
    }
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
?>
