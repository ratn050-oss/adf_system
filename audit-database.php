<?php
require_once 'config/config.php';

echo "========================================\n";
echo "DATABASE AUDIT - What Actually Exists\n";
echo "========================================\n\n";

// 1. Check adf_system (Master)
echo "1. MASTER DATABASE (adf_system):\n";
echo "   Connection: " . DB_HOST . " / adf_system\n";
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=adf_system;charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✓ Connected\n";
    echo "   Tables: " . count($tables) . "\n";
    
    $important = ['cash_accounts', 'cash_account_transactions', 'users', 'businesses'];
    foreach ($important as $tbl) {
        if (in_array($tbl, $tables)) {
            echo "      ✓ $tbl\n";
        } else {
            echo "      ✗ $tbl (MISSING)\n";
        }
    }
    
} catch (Exception $e) {
    echo "   ✗ ERROR: " . $e->getMessage() . "\n";
}

// 2. Check adf_narayana_hotel
echo "\n2. BUSINESS DATABASE (adf_narayana_hotel):\n";
echo "   Connection: " . DB_HOST . " / adf_narayana_hotel\n";
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=adf_narayana_hotel;charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✓ Connected\n";
    echo "   Tables: " . count($tables) . "\n";
    
    $important = ['cash_book', 'cashbook', 'cash_balance', 'bookings', 'rooms', 'users'];
    foreach ($important as $tbl) {
        if (in_array($tbl, $tables)) {
            echo "      ✓ $tbl\n";
        }
    }
    
} catch (Exception $e) {
    echo "   ✗ ERROR: " . $e->getMessage() . "\n";
}

// 3. Check adf_benscafe
echo "\n3... BUSINESS DATABASE (adf_benscafe):\n";
echo "   Connection: " . DB_HOST . " / adf_benscafe\n";
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=adf_benscafe;charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✓ Connected\n";
    echo "   Tables: " . count($tables) . "\n";
    
} catch (Exception $e) {
    echo "   ✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
?>
