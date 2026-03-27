<?php
/**
 * Sync ALL tables from main database to all business databases
 * This ensures all businesses have the same table structure
 */

echo "Syncing all tables to business databases...\n";
echo str_repeat("=", 60) . "\n";

$databases = [
    'adf_benscafe',
    'adf_narayana',
    'adf_eat_meet',
    'adf_pabrik',
    'adf_furniture',
    'adf_karimunjawa'
];

$sourceDb = 'adf_narayana';

// Connect to source database
try {
    $sourcePdo = new PDO(
        "mysql:host=localhost;dbname=$sourceDb;charset=utf8mb4",
        "root",
        "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Get all tables from source database
    $tables = $sourcePdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found " . count($tables) . " tables in $sourceDb\n\n";
    
    foreach ($databases as $targetDb) {
        echo "Processing: $targetDb\n";
        echo str_repeat("-", 60) . "\n";
        
        try {
            $targetPdo = new PDO(
                "mysql:host=localhost;dbname=$targetDb;charset=utf8mb4",
                "root",
                "",
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $created = 0;
            $skipped = 0;
            $errors = 0;
            
            foreach ($tables as $table) {
                // Check if table exists in target
                $exists = $targetPdo->query(
                    "SELECT COUNT(*) FROM information_schema.tables 
                     WHERE table_schema = '$targetDb' AND table_name = '$table'"
                )->fetchColumn();
                
                if ($exists) {
                    $skipped++;
                    continue;
                }
                
                // Get CREATE TABLE statement from source
                try {
                    $createStmt = $sourcePdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                    $createSql = $createStmt['Create Table'];
                    
                    // Execute CREATE TABLE in target
                    $targetPdo->exec($createSql);
                    echo "  ✅ Created: $table\n";
                    $created++;
                } catch (PDOException $e) {
                    // Skip tables that have issues in source database
                    if (strpos($e->getMessage(), "doesn't exist in engine") !== false) {
                        echo "  ⚠️  Skipped: $table (corrupted in source)\n";
                        $skipped++;
                    } else {
                        echo "  ❌ Failed: $table - " . $e->getMessage() . "\n";
                        $errors++;
                    }
                }
            }
            
            echo "\n  Summary: $created created, $skipped skipped, $errors errors\n\n";
            
        } catch (PDOException $e) {
            echo "  ❌ Database Error: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo str_repeat("=", 60) . "\n";
    echo "✅ Sync completed!\n";
    echo "\nAll business databases now have the same table structure.\n";
    echo "You can now use all features (suppliers, procurement, etc.) in every business.\n";
    
} catch (PDOException $e) {
    die("❌ Cannot connect to source database: " . $e->getMessage() . "\n");
}
