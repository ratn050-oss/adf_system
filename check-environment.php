<?php
/**
 * Check environment detection
 */

echo "=== ENVIRONMENT DETECTION ===\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? 'NOT SET') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'NOT SET') . "\n";

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

echo "Is Production: " . ($isProduction ? "YES (PRODUCTION)" : "NO (LOCAL)") . "\n";

// Check if adf_system database exists
echo "\n=== DATABASE CHECK ===\n";
try {
    // Try with production credentials (what system is using)
    $dsn = "mysql:host=localhost;charset=utf8mb4";
    $pdo = new PDO($dsn, 'adfb2574_adfsystem', '@Nnoc2025');
    echo "Production DB User (adfb2574_adfsystem): WORKS\n";
    
    // List available databases
    $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_ASSOC);
    echo "Available databases:\n";
    foreach ($dbs as $db) {
        echo "  - " . $db['Database'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Production DB User (adfb2574_adfsystem): FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
}

// Try with local credentials
echo "\nTrying local credentials...\n";
try {
    $dsn = "mysql:host=localhost;charset=utf8mb4";
    $pdo = new PDO($dsn, 'root', '');
    echo "Local DB User (root): WORKS\n";
    
    // List available databases
    $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_ASSOC);
    echo "Available databases:\n";
    foreach ($dbs as $db) {
        echo "  - " . $db['Database'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Local DB User (root): FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
}
?>
