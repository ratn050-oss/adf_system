<?php
/**
 * QUICK TEST - All Features
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

echo "<h2>TESTING ALL FEATURES</h2>";
echo "<hr>";

// 1. Test Cash Accounts Query (for dropdown)
echo "<h3>1. Cash Accounts (for Dropdown)</h3>";
try {
    // IMPORTANT: cash_accounts is in MASTER database (adf_system), not business database!
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $businessId = ACTIVE_BUSINESS_ID;
    
    $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? ORDER BY is_default_account DESC, account_name");
    $stmt->execute([$businessId]);
    $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Query SUCCESS - Found " . count($cashAccounts) . " accounts for business_id {$businessId}<br>";
    foreach ($cashAccounts as $acc) {
        echo "- ID:{$acc['id']} {$acc['account_name']} ({$acc['account_type']})<br>";
    }
} catch (Exception $e) {
    echo "❌ Query FAILED: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 2. Test Today Transactions (for dashboard fix)
echo "<h3>2. Today Transactions</h3>";
try {
    $today = date('Y-m-d');
    $result = $db->fetchAll(
        "SELECT transaction_type, COUNT(*) as count, SUM(amount) as total 
         FROM cash_book 
         WHERE transaction_date = :date 
         GROUP BY transaction_type",
        ['date' => $today]
    );
    
    if (empty($result)) {
        echo "⚠️ No transactions today ({$today})<br>";
    } else {
        echo "✅ Found transactions:<br>";
        foreach ($result as $row) {
            echo "- {$row['transaction_type']}: {$row['count']} txn, Total: Rp " . number_format($row['total'], 0, ',', '.') . "<br>";
        }
    }
    
    // Check if there are transactions in the last 7 days
    $recent = $db->fetchOne("SELECT COUNT(*) as count FROM cash_book WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    echo "Last 7 days: {$recent['count']} transactions<br>";
    
} catch (Exception $e) {
    echo "❌ Query FAILED: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 3. Test Capital Stats (for widget)
echo "<h3>3. Kas Modal Owner Stats</h3>";
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = ACTIVE_BUSINESS_ID;
    $thisMonth = date('Y-m');
    
    // Get capital account
    $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital' LIMIT 1");
    $stmt->execute([$businessId]);
    $capitalAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($capitalAccount) {
        echo "✅ Capital Account Found: {$capitalAccount['account_name']}<br>";
        
        // Get stats
        $stmt = $masterDb->prepare("
            SELECT 
                SUM(CASE WHEN transaction_type = 'capital_injection' THEN amount ELSE 0 END) as received,
                SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as used,
                SUM(CASE WHEN transaction_type = 'capital_injection' THEN amount ELSE -amount END) as balance
            FROM cash_account_transactions 
            WHERE cash_account_id = ? 
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ");
        $stmt->execute([$capitalAccount['id'], $thisMonth]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "Modal Diterima: Rp " . number_format($stats['received'] ?? 0, 0, ',', '.') . "<br>";
        echo "Modal Digunakan: Rp " . number_format($stats['used'] ?? 0, 0, ',', '.') . "<br>";
        echo "Saldo Modal: Rp " . number_format($stats['balance'] ?? 0, 0, ',', '.') . "<br>";
    } else {
        echo "⚠️ No capital account found<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Query FAILED: " . $e->getMessage() . "<br>";
}
echo "<hr>";

echo "<h3>4. Database Structure Check</h3>";
try {
    // Check if is_active column exists in cash_accounts (MASTER DB)
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $stmt = $masterDb->query("SHOW COLUMNS FROM cash_accounts");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasIsActive = false;
    $hasIsDefault = false;
    echo "cash_accounts columns (MASTER DB: adf_system):<br>";
    foreach ($cols as $col) {
        echo "- {$col['Field']} ({$col['Type']})<br>";
        if ($col['Field'] === 'is_active') $hasIsActive = true;
        if ($col['Field'] === 'is_default_account') $hasIsDefault = true;
    }
    
    if (!$hasIsActive) {
        echo "<strong>⚠️ WARNING: is_active column NOT FOUND!</strong><br>";
    } else {
        echo "✅ is_active column exists<br>";
    }
    
    if (!$hasIsDefault) {
        echo "<strong>⚠️ WARNING: is_default_account column NOT FOUND!</strong><br>";
    } else {
        echo "✅ is_default_account column exists<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Check FAILED: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>✅ Test Complete</h3>";
echo "<a href='index.php'>Go to Dashboard</a> | ";
echo "<a href='modules/cashbook/add.php'>Test Cashbook Form</a>";
?>
