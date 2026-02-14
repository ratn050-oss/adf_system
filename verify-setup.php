<?php
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

try {
    $db = Database::getInstance();
    
    // 1. Check tabel cash_accounts
    $tables = $db->fetchAll("SHOW TABLES LIKE 'cash_accounts'");
    echo "✓ Tabel cash_accounts: " . (count($tables) > 0 ? 'ADA ✓' : 'TIDAK ADA ✗') . "\n";
    
    // 2. Check tabel cash_account_transactions
    $tables2 = $db->fetchAll("SHOW TABLES LIKE 'cash_account_transactions'");
    echo "✓ Tabel cash_account_transactions: " . (count($tables2) > 0 ? 'ADA ✓' : 'TIDAK ADA ✗') . "\n";
    
    // 3. Check data cash_accounts
    $accounts = $db->fetchAll("SELECT COUNT(id) as cnt FROM cash_accounts");
    $count = $accounts[0]['cnt'] ?? 0;
    echo "✓ Data cash_accounts: $count records\n";
    
    // 4. Check kolom di cash_book
    $columns = $db->fetchAll("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    echo "✓ Kolom cash_account_id: " . (count($columns) > 0 ? 'ADA ✓' : 'TIDAK ADA ✗') . "\n";
    
    echo "\n📊 Detail Cash Accounts:\n";
    $detailed = $db->fetchAll("SELECT id, business_id, account_name, account_type FROM cash_accounts LIMIT 10");
    foreach ($detailed as $row) {
        echo "  - {$row['account_name']} ({$row['account_type']}) - Business ID: {$row['business_id']}\n";
    }
    
    echo "\n✅ SETUP BERHASIL!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
