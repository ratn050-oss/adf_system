<?php
// Diagnostic script to check cash_book cash_account_id values
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';
require_once 'config/database.php';

// Get business database connection
$db = Database::getInstance();

echo "<h2>🔍 Check Cash Book - Cash Account ID Values</h2>";

try {
    // Check column exists
    $columns = $db->fetchAll("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ Column <code>cash_account_id</code> does NOT exist in cash_book table!</p>";
        echo "<p>Run this SQL to add it:</p>";
        echo "<pre>ALTER TABLE cash_book ADD COLUMN cash_account_id INT NULL AFTER reference_number;</pre>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Column <code>cash_account_id</code> exists in cash_book</p>";
    
    // Get count of records with NULL cash_account_id
    $nullCount = $db->fetchOne("SELECT COUNT(*) as count FROM cash_book WHERE cash_account_id IS NULL");
    echo "<p><strong>Records with NULL cash_account_id:</strong> " . $nullCount['count'] . "</p>";
    
    // Get count of records with NOT NULL cash_account_id
    $notNullCount = $db->fetchOne("SELECT COUNT(*) as count FROM cash_book WHERE cash_account_id IS NOT NULL");
    echo "<p><strong>Records with cash_account_id set:</strong> " . $notNullCount['count'] . "</p>";
    
    // Get this month's transactions
    $thisMonth = date('Y-m');
    echo "<hr>";
    echo "<h3>📅 This Month's Transactions ({$thisMonth})</h3>";
    
    $monthlyTransactions = $db->fetchAll("
        SELECT 
            id, 
            transaction_date, 
            description, 
            transaction_type, 
            amount, 
            cash_account_id 
        FROM cash_book 
        WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ORDER BY transaction_date DESC
    ", [$thisMonth]);
    
    if (empty($monthlyTransactions)) {
        echo "<p style='color: orange;'>⚠️ No transactions found for this month</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th>";
        echo "<th>Date</th>";
        echo "<th>Description</th>";
        echo "<th>Type</th>";
        echo "<th>Amount</th>";
        echo "<th>Cash Account ID</th>";
        echo "</tr>";
        
        $totalIncome = 0;
        $totalExpense = 0;
        $incomeWithNull = 0;
        
        foreach ($monthlyTransactions as $row) {
            $bgColor = $row['transaction_type'] == 'income' ? '#d4edda' : '#f8d7da';
            echo "<tr style='background: {$bgColor};'>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['transaction_date']}</td>";
            echo "<td>" . htmlspecialchars($row['description']) . "</td>";
            echo "<td>{$row['transaction_type']}</td>";
            echo "<td>Rp " . number_format($row['amount'], 0, ',', '.') . "</td>";
            
            if ($row['cash_account_id'] === null) {
                echo "<td style='color: red; font-weight: bold;'>NULL</td>";
                if ($row['transaction_type'] == 'income') {
                    $incomeWithNull += $row['amount'];
                }
            } else {
                echo "<td style='color: green;'>{$row['cash_account_id']}</td>";
            }
            echo "</tr>";
            
            if ($row['transaction_type'] == 'income') {
                $totalIncome += $row['amount'];
            } else {
                $totalExpense += $row['amount'];
            }
        }
        
        echo "</table>";
        
        echo "<hr>";
        echo "<h3>Summary</h3>";
        echo "<p><strong>Total Income:</strong> Rp " . number_format($totalIncome, 0, ',', '.') . "</p>";
        echo "<p><strong>Total Expense:</strong> Rp " . number_format($totalExpense, 0, ',', '.') . "</p>";
        echo "<p style='color: red;'><strong>Income with NULL cash_account_id:</strong> Rp " . number_format($incomeWithNull, 0, ',', '.') . "</p>";
        echo "<p style='color: orange;'><strong>⚠️ These NULL transactions will be counted as OPERATIONAL income!</strong></p>";
    }
    
    // Check master DB for owner capital account
    echo "<hr>";
    echo "<h3>🏦 Owner Capital Accounts (Master DB)</h3>";
    
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessId = $businessMapping[$businessIdentifier] ?? 1;
    
    $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($ownerAccounts)) {
        echo "<p style='color: red;'>❌ No owner capital account found!</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Account Name</th><th>Type</th></tr>";
        foreach ($ownerAccounts as $acc) {
            echo "<tr>";
            echo "<td>{$acc['id']}</td>";
            echo "<td>{$acc['account_name']}</td>";
            echo "<td>{$acc['account_type']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        $ownerAccountIds = array_column($ownerAccounts, 'id');
        echo "<p><strong>Owner Capital Account IDs:</strong> " . implode(', ', $ownerAccountIds) . "</p>";
        
        // Check if any cash_book records reference these IDs
        $placeholders = implode(',', $ownerAccountIds);
        $linkedCountResult = $db->fetchOne("SELECT COUNT(*) as count FROM cash_book WHERE cash_account_id IN ($placeholders)");
        $linkedCount = $linkedCountResult['count'];
        
        echo "<p><strong>Cash book records linked to owner capital:</strong> {$linkedCount}</p>";
        
        if ($linkedCount == 0) {
            echo "<p style='color: red; font-weight: bold;'>❌ NO cash_book records are linked to owner capital accounts!</p>";
            echo "<p style='color: orange;'>This means ALL income is counted as operational income.</p>";
            echo "<p><strong>Solution:</strong> When adding transactions to cashbook, make sure to select the correct cash account from the dropdown!</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>← Back to Dashboard</a> | <a href='modules/cashbook/add.php'>Add Transaction</a></p>";
?>
