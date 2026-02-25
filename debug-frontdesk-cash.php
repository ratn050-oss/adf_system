<?php
/**
 * Debug script for frontdesk cash data
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$today = date('Y-m-d');

echo "<h2>Debug Frontdesk Cash Data</h2>";
echo "<p>Today: $today</p>";

// Check cash_book table
$tables = $db->fetchAll("SHOW TABLES LIKE 'cash_book'");
echo "<p>cash_book exists: " . (count($tables) > 0 ? 'YES' : 'NO') . "</p>";

if (count($tables) > 0) {
    $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM cash_book");
    echo "<p>Total records: " . $count['cnt'] . "</p>";
    
    // Get owner_capital account IDs from master DB
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessId = getMasterBusinessId();
    
    echo "<p>Business ID: $businessId</p>";
    
    $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Owner Capital Accounts:</p><pre>";
    print_r($ownerAccounts);
    echo "</pre>";
    
    $ownerCapitalIds = array_column($ownerAccounts, 'id');
    
    // Build exclusion clause
    $excludeOwnerCapital = '';
    if (!empty($ownerCapitalIds)) {
        $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
    }
    
    echo "<p>Exclude clause: $excludeOwnerCapital</p>";
    
    // Today's income (excluding owner capital)
    $todayIncome = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND transaction_date = ?" . $excludeOwnerCapital,
        [$today]
    );
    echo "<p style='color:green;'>Today Income (excl owner): Rp " . number_format($todayIncome['total'], 0, ',', '.') . "</p>";
    
    // Today's expense
    $todayExpense = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND transaction_date = ?",
        [$today]
    );
    echo "<p style='color:red;'>Today Expense: Rp " . number_format($todayExpense['total'], 0, ',', '.') . "</p>";
    
    // Owner transfer today
    $ownerToday = 0;
    if (!empty($ownerCapitalIds)) {
        $placeholders = implode(',', array_fill(0, count($ownerCapitalIds), '?'));
        $ownerResult = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
             WHERE transaction_type = 'income' AND transaction_date = ?
             AND cash_account_id IN ($placeholders)",
            array_merge([$today], $ownerCapitalIds)
        );
        $ownerToday = $ownerResult['total'] ?? 0;
    }
    echo "<p style='color:blue;'>Owner Transfer Today: Rp " . number_format($ownerToday, 0, ',', '.') . "</p>";
    
    // Total balance
    $totalIncome = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income'" . $excludeOwnerCapital
    );
    $totalExpense = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'expense'"
    );
    $balance = ($totalIncome['total'] ?? 0) - ($totalExpense['total'] ?? 0);
    echo "<p><strong>Cash Balance: Rp " . number_format($balance, 0, ',', '.') . "</strong></p>";
    
    // Show recent transactions
    echo "<h3>Recent Transactions (last 5):</h3>";
    $recent = $db->fetchAll("SELECT * FROM cash_book ORDER BY transaction_date DESC, id DESC LIMIT 5");
    echo "<table border='1'><tr><th>ID</th><th>Date</th><th>Type</th><th>Amount</th><th>Account ID</th><th>Description</th></tr>";
    foreach ($recent as $r) {
        echo "<tr><td>{$r['id']}</td><td>{$r['transaction_date']}</td><td>{$r['transaction_type']}</td><td>" . number_format($r['amount']) . "</td><td>{$r['cash_account_id']}</td><td>{$r['description']}</td></tr>";
    }
    echo "</table>";
}
?>
