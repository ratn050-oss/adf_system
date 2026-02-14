<?php
/**
 * COMPLETE WORKFLOW TEST
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

echo "<h2>✅ COMPLETE WORKFLOW TEST</h2>";
echo "<hr>";

// Get business mapping
$businessIdentifier = ACTIVE_BUSINESS_ID;
$businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
$businessId = $businessMapping[$businessIdentifier] ?? 1;

echo "<h3>1. Configuration</h3>";
echo "Active Business: <strong>{$businessIdentifier}</strong> (DB ID: {$businessId})<br>";
echo "<hr>";

// Get owner capital account
echo "<h3>2. Cash Accounts</h3>";
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY account_type");
    $stmt->execute([$businessId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ownerCapitalId = null;
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Default</th></tr>";
    foreach ($accounts as $acc) {
        $highlight = ($acc['account_type'] === 'owner_capital') ? " style='background: #fef3c7;'" : "";
        echo "<tr{$highlight}>";
        echo "<td>{$acc['id']}</td>";
        echo "<td><strong>{$acc['account_name']}</strong></td>";
        echo "<td>{$acc['account_type']}</td>";
        echo "<td>" . ($acc['is_default_account'] ? '⭐' : '') . "</td>";
        echo "</tr>";
        
        if ($acc['account_type'] === 'owner_capital') {
            $ownerCapitalId = $acc['id'];
        }
    }
    echo "</table>";
    
    echo "<br>Owner Capital Account ID: <strong style='color: orange;'>{$ownerCapitalId}</strong><br>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Check exclusion logic
echo "<h3>3. Exclusion Logic Test</h3>";
try {
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($ownerCapitalAccountIds)) {
        $excludeClause = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
        echo "✅ Exclusion clause built:<br>";
        echo "<code style='background: #f3f4f6; padding: 0.5rem; display: block; margin: 0.5rem 0;'>{$excludeClause}</code>";
    } else {
        echo "❌ No owner capital accounts found!<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Test operational income query
echo "<h3>4. Income Query Test (Should EXCLUDE Owner Capital)</h3>";
try {
    // All income (including owner capital)
    $allIncome = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income'");
    
    // Operational income only (exclude owner capital)
    $operationalIncome = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income'" . $excludeClause
    );
    
    // Owner capital income only
    $ownerCapitalIncome = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND cash_account_id = ?",
        [$ownerCapitalId]
    );
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr>";
    echo "<th>All Income (Total)</th>";
    echo "<th>Operational Income (Exclude Modal)</th>";
    echo "<th>Owner Capital Income</th>";
    echo "</tr>";
    echo "<tr>";
    echo "<td style='text-align: right;'><strong>Rp " . number_format($allIncome['total'], 0, ',', '.') . "</strong></td>";
    echo "<td style='text-align: right; background: #d1fae5;'><strong>Rp " . number_format($operationalIncome['total'], 0, ',', '.') . "</strong></td>";
    echo "<td style='text-align: right; background: #fef3c7;'><strong>Rp " . number_format($ownerCapitalIncome['total'], 0, ',', '.') . "</strong></td>";
    echo "</tr>";
    echo "</table>";
    
    $diff = $allIncome['total'] - $operationalIncome['total'];
    echo "<br>Difference: <strong>Rp " . number_format($diff, 0, ',', '.') . "</strong>";
    
    if ($diff == $ownerCapitalIncome['total']) {
        echo " <span style='color: green;'>✅ CORRECT!</span><br>";
    } else {
        echo " <span style='color: red;'>❌ MISMATCH!</span><br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Check recent transactions
echo "<h3>5. Recent Transactions (All Types)</h3>";
$recent = $db->fetchAll("
    SELECT 
        cb.*,
        c.category_name,
        ca.account_name,
        ca.account_type
    FROM cash_book cb
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN (" . DB_NAME . ".cash_accounts ca) ON cb.cash_account_id = ca.id
    ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
    LIMIT 5
");

if (empty($recent)) {
    echo "No transactions found.<br>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 0.9rem;'>";
    echo "<tr><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Cash Account</th><th>Account Type</th></tr>";
    foreach ($recent as $tx) {
        $typeColor = ($tx['transaction_type'] === 'income') ? '#10b981' : '#ef4444';
        $accountHighlight = ($tx['account_type'] === 'owner_capital') ? " style='background: #fef3c7;'" : "";
        
        echo "<tr{$accountHighlight}>";
        echo "<td>{$tx['transaction_date']}</td>";
        echo "<td style='color: {$typeColor};'><strong>{$tx['transaction_type']}</strong></td>";
        echo "<td>{$tx['category_name']}</td>";
        echo "<td style='text-align: right;'>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
        echo "<td>" . ($tx['account_name'] ?: '-') . "</td>";
        echo "<td>" . ($tx['account_type'] ?: '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// Summary
echo "<h3>✅ TEST SUMMARY</h3>";
echo "<div style='background: #d1fae5; padding: 1rem; border-left: 4px solid #10b981; border-radius: 4px;'>";
echo "<strong>System is working correctly if:</strong><br>";
echo "1. ✅ Owner Capital account exists<br>";
echo "2. ✅ Exclusion clause is built<br>";
echo "3. ✅ Operational Income = All Income - Owner Capital Income<br>";
echo "4. ✅ Transactions with Owner Capital account are highlighted<br>";
echo "</div>";

echo "<hr>";
echo "<a href='index.php' style='padding: 1rem 2rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; display: inline-block; margin-right: 1rem;'>Go to Dashboard</a>";
echo "<a href='modules/cashbook/add.php' style='padding: 1rem 2rem; background: #10b981; color: white; text-decoration: none; border-radius: 8px; display: inline-block;'>Add Transaction</a>";
?>
