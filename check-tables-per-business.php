<?php
/**
 * Cek tabel per bisnis
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Cek Tabel per Bisnis</h1><pre>";

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'adfb2574_adfsystem');
    define('DB_PASS', '@Nnoc2025');
    $databases = [
        'Hotel' => 'adfb2574_narayana_hotel',
        'Bens Cafe' => 'adfb2574_Adf_Bens'
    ];
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    $databases = [
        'Hotel' => 'adf_narayana_hotel', 
        'Bens Cafe' => 'adf_benscafe'
    ];
}

$tables_to_check = ['suppliers', 'purchase_orders_header', 'purchases_header', 'cash_book', 'bookings'];

foreach ($databases as $bizName => $dbName) {
    echo "\n=== {$bizName} ({$dbName}) ===\n";
    
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname={$dbName};charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "✓ Connected\n\n";
        
        foreach ($tables_to_check as $table) {
            $result = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
            if ($result) {
                $count = $pdo->query("SELECT COUNT(*) as cnt FROM {$table}")->fetch();
                echo "  {$table}: ✓ EXISTS ({$count['cnt']} rows)\n";
            } else {
                echo "  {$table}: ✗ NOT EXISTS\n";
            }
        }
        
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n</pre>";
