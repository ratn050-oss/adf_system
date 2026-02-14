<?php
require_once 'config/database.php';

// Connect to business DB
$businessDb = new PDO("mysql:host=localhost;dbname=adf_narayana_hotel", "root", "");

echo "<h2>🔧 Fix Duplicate Transactions</h2>";
echo "<p>Transaksi IDs: 1233, 1234, 1238 - Income 500,000 tercatat 3x (seharusnya 1x)</p>";
echo "<hr>";

// Show transactions
echo "<h3>Detail Transaksi Duplikat:</h3>";
$stmt = $businessDb->prepare("
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.transaction_type,
        cb.amount,
        cb.description,
        cb.cash_account_id,
        ca.account_name,
        ca.account_type
    FROM cash_book cb
    LEFT JOIN adf_system.cash_accounts ca ON cb.cash_account_id = ca.id
    WHERE cb.id IN (1233, 1234, 1238)
    ORDER BY cb.id
");
$stmt->execute();
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Date</th><th>Account</th><th>Type</th><th>Amount</th><th>Description</th><th>Action</th></tr>";

foreach ($duplicates as $dup) {
    echo "<tr>";
    echo "<td>{$dup['id']}</td>";
    echo "<td>{$dup['transaction_date']}</td>";
    echo "<td>{$dup['account_name']} ({$dup['account_type']})</td>";
    echo "<td>{$dup['transaction_type']}</td>";
    echo "<td>" . number_format($dup['amount']) . "</td>";
    echo "<td>{$dup['description']}</td>";
    
    // Keep ID 1233 (Modal Owner), delete 1234 and 1238 (duplicate Petty Cash)
    if ($dup['id'] == 1233) {
        echo "<td style='color: green; font-weight: bold;'>✅ KEEP (Modal Owner)</td>";
    } else {
        echo "<td style='color: red;'><a href='?delete={$dup['id']}'>🗑️ DELETE</a></td>";
    }
    echo "</tr>";
}
echo "</table>";

// Handle delete
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    
    // Also delete from cash_account_transactions
    $stmt = $businessDb->prepare("SELECT cash_account_id FROM cash_book WHERE id = ?");
    $stmt->execute([$deleteId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $accountId = $result['cash_account_id'];
    
    // Delete from cash_account_transactions in master DB
    $masterDb = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
    $stmt = $masterDb->prepare("
        DELETE FROM cash_account_transactions 
        WHERE cash_account_id = ? 
        AND transaction_date = (SELECT transaction_date FROM adf_narayana_hotel.cash_book WHERE id = ?)
        AND amount = (SELECT amount FROM adf_narayana_hotel.cash_book WHERE id = ?)
        AND transaction_type = (SELECT transaction_type FROM adf_narayana_hotel.cash_book WHERE id = ?)
        LIMIT 1
    ");
    $stmt->execute([$accountId, $deleteId, $deleteId, $deleteId]);
    
    // Delete from cash_book
    $stmt = $businessDb->prepare("DELETE FROM cash_book WHERE id = ?");
    $stmt->execute([$deleteId]);
    
    // Update current_balance in cash_accounts
    $stmt = $businessDb->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) - 
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
        FROM cash_book 
        WHERE cash_account_id = ?
    ");
    $stmt->execute([$accountId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $newBalance = $result['balance'];
    
    $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?");
    $stmt->execute([$newBalance, $accountId]);
    
    echo "<div style='padding: 10px; background: #d4edda; color: #155724; margin: 10px 0; border-radius: 5px;'>";
    echo "✅ Transaction ID $deleteId deleted successfully!<br>";
    echo "✅ Updated current_balance for account $accountId to " . number_format($newBalance);
    echo "</div>";
    echo "<script>setTimeout(function(){ window.location.href = 'fix-duplicate-transactions.php'; }, 2000);</script>";
}

// Show expected result
echo "<hr>";
echo "<h3>💡 Expected Result After Fix:</h3>";
echo "<div style='padding: 10px; background: #f0f9ff; border-left: 4px solid #3b82f6;'>";
echo "<p><strong>Petty Cash:</strong></p>";
echo "<ul>";
echo "<li>Total Income: <span style='color: green;'>500,000</span> (not 1,000,000)</li>";
echo "<li>Total Expense: <span style='color: red;'>500,000</span></li>";
echo "<li>Balance: <span style='color: green; font-weight: bold;'>0</span></li>";
echo "</ul>";

echo "<p><strong>Modal Owner:</strong></p>";
echo "<ul>";
echo "<li>Total Income: <span style='color: green;'>500,000</span></li>";
echo "<li>Total Expense: <span style='color: red;'>1,100,000</span></li>";
echo "<li>Balance: <span style='color: red; font-weight: bold;'>-600,000</span></li>";
echo "</ul>";

echo "<p><strong>TOTAL KAS OPERASIONAL:</strong></p>";
echo "<ul>";
echo "<li>Petty Cash: 0</li>";
echo "<li>Modal Owner: -600,000</li>";
echo "<li>TOTAL: <span style='color: red; font-weight: bold;'>-600,000</span></li>";
echo "</ul>";
echo "</div>";

echo "<p style='color: orange; margin-top: 15px;'><strong>Note:</strong> Setelah fix duplikat, Modal Owner tetap -600rb karena memang ada expense 1,100,000 dari akun tersebut. Jika seharusnya cuma -100rb, berarti ada expense lain yang perlu dicek.</p>";

echo "<hr>";
echo "<p><a href='debug-report-balance.php'>🔍 Back to Debug</a> | <a href='modules/reports/daily.php'>📊 View Report</a></p>";
?>
