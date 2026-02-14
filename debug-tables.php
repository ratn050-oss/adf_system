<?php
/**
 * Check tabel structure dan fix
 */
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

echo "🔍 CHECKING TABLE STRUCTURE\n";
echo "=======================\n\n";

try {
    // Check cash_accounts structure
    echo "1. Cash Accounts Table Structure:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM cash_accounts");
    $columns = $cols->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "   - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Check cash_book structure
    echo "\n2. Cash Book Table Structure:\n";
    $cols2 = $pdo->query("SHOW COLUMNS FROM cash_book");
    $columns2 = $cols2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns2 as $col) {
        if (strpos($col['Field'], 'cash') !== false || strpos($col['Field'], 'id') !== false) {
            echo "   - {$col['Field']} ({$col['Type']})\n";
        }
    }
    
    // Check businesses
    echo "\n3. Businesses Info:\n";
    $biz = $pdo->query("SELECT id, business_name FROM businesses LIMIT 3");
    $businesses = $biz->fetchAll(PDO::FETCH_ASSOC);
    foreach ($businesses as $b) {
        echo "   - ID: {$b['id']} | Name: {$b['business_name']}\n";
    }
    
    // Try simple insert
    echo "\n4. Testing Simple Insert:\n";
    try {
        $test = $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, is_active) VALUES (?, ?, ?, ?)");
        $test->execute([1, 'Test Account', 'cash', 1]);
        echo "   ✅ Insert successful\n";
        
        // Delete test
        $pdo->exec("DELETE FROM cash_accounts WHERE account_name = 'Test Account'");
        echo "   ✅ Cleanup done\n";
    } catch (PDOException $e) {
        echo "   ❌ Insert error: " . $e->getMessage() . "\n";
    }
    
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
