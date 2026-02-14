<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get business ID
$businessSlug = 'narayana-hotel';
$businessId = 1;

// Connect to both databases
$masterDb = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
$businessDb = new PDO("mysql:host=localhost;dbname=adf_narayana_hotel", "root", "");

echo "<h2>🔍 DEBUG: Report Balance Issue</h2>";
echo "<p>Mengecek kenapa ada -600rb di laporan, seharusnya -100rb</p>";
echo "<hr>";

// Get all account IDs
echo "<h3>📋 Step 1: Get All Cash Accounts</h3>";
$stmt = $masterDb->prepare("SELECT id, account_name, account_type, current_balance FROM cash_accounts WHERE business_id = ?");
$stmt->execute([$businessId]);
$allAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pettyCashAccounts = [];
$modalOwnerAccounts = [];

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Current Balance (Master DB)</th></tr>";
foreach ($allAccounts as $acc) {
    echo "<tr>";
    echo "<td>{$acc['id']}</td>";
    echo "<td>{$acc['account_name']}</td>";
    echo "<td>{$acc['account_type']}</td>";
    echo "<td style='text-align: right;'>" . number_format($acc['current_balance']) . "</td>";
    echo "</tr>";
    
    if ($acc['account_type'] === 'cash') {
        $pettyCashAccounts[] = $acc['id'];
    } elseif ($acc['account_type'] === 'owner_capital') {
        $modalOwnerAccounts[] = $acc['id'];
    }
}
echo "</table>";

echo "<p><strong>Petty Cash Account IDs:</strong> " . implode(', ', $pettyCashAccounts) . "</p>";
echo "<p><strong>Modal Owner Account IDs:</strong> " . implode(', ', $modalOwnerAccounts) . "</p>";

// Query balance from cash_book for each account
echo "<hr>";
echo "<h3>📊 Step 2: Calculate Balance from cash_book (Business DB)</h3>";

foreach ($allAccounts as $acc) {
    echo "<h4>{$acc['account_name']} (ID: {$acc['id']})</h4>";
    
    $stmt = $businessDb->prepare("
        SELECT 
            transaction_type,
            COUNT(*) as count,
            SUM(amount) as total
        FROM cash_book 
        WHERE cash_account_id = ?
        GROUP BY transaction_type
    ");
    $stmt->execute([$acc['id']]);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $income = 0;
    $expense = 0;
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Type</th><th>Count</th><th>Total</th></tr>";
    foreach ($summary as $row) {
        echo "<tr>";
        echo "<td>{$row['transaction_type']}</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td style='text-align: right;'>" . number_format($row['total']) . "</td>";
        echo "</tr>";
        
        if ($row['transaction_type'] === 'income') {
            $income = $row['total'];
        } else {
            $expense = $row['total'];
        }
    }
    $balance = $income - $expense;
    echo "<tr style='font-weight: bold; background: #f0f0f0;'>";
    echo "<td>BALANCE</td>";
    echo "<td colspan='2' style='text-align: right; color: " . ($balance >= 0 ? 'green' : 'red') . ";'>" . number_format($balance) . "</td>";
    echo "</tr>";
    echo "</table>";
}

// Get latest transactions
echo "<hr>";
echo "<h3>📝 Step 3: Latest 20 Transactions</h3>";
$stmt = $businessDb->prepare("
    SELECT 
        cb.*,
        ca.account_name,
        cat.category_name
    FROM cash_book cb
    LEFT JOIN adf_system.cash_accounts ca ON cb.cash_account_id = ca.id
    LEFT JOIN categories cat ON cb.category_id = cat.id
    ORDER BY cb.transaction_date DESC, cb.id DESC
    LIMIT 20
");
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 12px;'>";
echo "<tr><th>ID</th><th>Date</th><th>Account</th><th>Type</th><th>Category</th><th>Amount</th><th>Description</th></tr>";
foreach ($transactions as $trx) {
    $color = $trx['transaction_type'] === 'income' ? 'green' : 'red';
    $highlight = strpos($trx['description'], '[AUTO:') !== false ? 'background: #fff3cd;' : '';
    
    echo "<tr style='$highlight'>";
    echo "<td>{$trx['id']}</td>";
    echo "<td>{$trx['transaction_date']}</td>";
    echo "<td>{$trx['account_name']}</td>";
    echo "<td style='color: $color;'>{$trx['transaction_type']}</td>";
    echo "<td>{$trx['category_name']}</td>";
    echo "<td style='text-align: right; color: $color;'>" . number_format($trx['amount']) . "</td>";
    echo "<td style='max-width: 300px; word-wrap: break-word;'>{$trx['description']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check for duplicate transactions (same amount, same date)
echo "<hr>";
echo "<h3>⚠️ Step 4: Check for Potential Duplicate Transactions</h3>";
$stmt = $businessDb->prepare("
    SELECT 
        transaction_date,
        amount,
        transaction_type,
        description,
        COUNT(*) as count,
        GROUP_CONCAT(id) as transaction_ids,
        GROUP_CONCAT(cash_account_id) as account_ids
    FROM cash_book
    WHERE transaction_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY transaction_date, amount, transaction_type
    HAVING COUNT(*) > 1
    ORDER BY transaction_date DESC
");
$stmt->execute();
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "<p style='color: green;'>✅ No duplicate transactions found in the last 7 days</p>";
} else {
    echo "<p style='color: orange;'>⚠️ Found potential duplicate transactions:</p>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Date</th><th>Amount</th><th>Type</th><th>Count</th><th>Transaction IDs</th><th>Account IDs</th><th>Description</th></tr>";
    foreach ($duplicates as $dup) {
        echo "<tr>";
        echo "<td>{$dup['transaction_date']}</td>";
        echo "<td style='text-align: right;'>" . number_format($dup['amount']) . "</td>";
        echo "<td>{$dup['transaction_type']}</td>";
        echo "<td style='color: red; font-weight: bold;'>{$dup['count']}</td>";
        echo "<td>{$dup['transaction_ids']}</td>";
        echo "<td>{$dup['account_ids']}</td>";
        echo "<td>{$dup['description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Summary
echo "<hr>";
echo "<h3>📈 Step 5: Balance Summary</h3>";

// Petty Cash
if (!empty($pettyCashAccounts)) {
    $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
    $stmt = $businessDb->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) - 
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
        FROM cash_book 
        WHERE cash_account_id IN ($placeholders)
    ");
    $stmt->execute($pettyCashAccounts);
    $pettyCash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div style='padding: 10px; border: 2px solid #10b981; margin: 10px 0;'>";
    echo "<h4 style='color: #10b981;'>💵 Petty Cash Balance</h4>";
    echo "<p>Total Income: <strong style='color: green;'>" . number_format($pettyCash['total_income']) . "</strong></p>";
    echo "<p>Total Expense: <strong style='color: red;'>" . number_format($pettyCash['total_expense']) . "</strong></p>";
    echo "<p>Balance: <strong style='color: " . ($pettyCash['balance'] >= 0 ? 'green' : 'red') . "; font-size: 18px;'>" . number_format($pettyCash['balance']) . "</strong></p>";
    echo "</div>";
}

// Modal Owner
if (!empty($modalOwnerAccounts)) {
    $placeholders = implode(',', array_fill(0, count($modalOwnerAccounts), '?'));
    $stmt = $businessDb->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) - 
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
        FROM cash_book 
        WHERE cash_account_id IN ($placeholders)
    ");
    $stmt->execute($modalOwnerAccounts);
    $modalOwner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div style='padding: 10px; border: 2px solid #f59e0b; margin: 10px 0;'>";
    echo "<h4 style='color: #f59e0b;'>🔥 Modal Owner Balance</h4>";
    echo "<p>Total Income: <strong style='color: green;'>" . number_format($modalOwner['total_income']) . "</strong></p>";
    echo "<p>Total Expense: <strong style='color: red;'>" . number_format($modalOwner['total_expense']) . "</strong></p>";
    echo "<p>Balance: <strong style='color: " . ($modalOwner['balance'] >= 0 ? 'green' : 'red') . "; font-size: 18px;'>" . number_format($modalOwner['balance']) . "</strong></p>";
    echo "</div>";
}

// Total
if (isset($pettyCash) && isset($modalOwner)) {
    $totalBalance = $pettyCash['balance'] + $modalOwner['balance'];
    echo "<div style='padding: 10px; border: 3px solid #3b82f6; margin: 10px 0; background: #f0f9ff;'>";
    echo "<h4 style='color: #3b82f6;'>💰 TOTAL KAS OPERASIONAL</h4>";
    echo "<p>Petty Cash: <strong>" . number_format($pettyCash['balance']) . "</strong></p>";
    echo "<p>Modal Owner: <strong>" . number_format($modalOwner['balance']) . "</strong></p>";
    echo "<p>TOTAL: <strong style='color: " . ($totalBalance >= 0 ? 'green' : 'red') . "; font-size: 20px;'>" . number_format($totalBalance) . "</strong></p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='modules/reports/daily.php'>← Back to Daily Report</a> | <a href='debug-balances.php'>Debug Balances</a></p>";
?>
