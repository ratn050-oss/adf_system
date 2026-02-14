<?php
require_once 'config/database.php';

// Connect to databases
$masterDb = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
$businessDb = new PDO("mysql:host=localhost;dbname=adf_narayana_hotel", "root", "");

echo "<h2>🔍 Check Remaining Transactions</h2>";
echo "<hr>";

// Get all accounts
echo "<h3>📋 All Cash Accounts:</h3>";
$stmt = $masterDb->prepare("SELECT id, account_name, account_type, current_balance FROM cash_accounts WHERE business_id = 1 ORDER BY account_type");
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Account Name</th><th>Type</th><th>Current Balance</th></tr>";
$pettyCashAccounts = [];
$modalOwnerAccounts = [];

foreach ($accounts as $acc) {
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

echo "<p><strong>Petty Cash IDs:</strong> " . implode(', ', $pettyCashAccounts) . "</p>";
echo "<p><strong>Modal Owner IDs:</strong> " . implode(', ', $modalOwnerAccounts) . "</p>";

// Get all income transactions
echo "<hr>";
echo "<h3>💰 All Income Transactions:</h3>";
$stmt = $businessDb->prepare("
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.cash_account_id,
        ca.account_name,
        ca.account_type,
        cb.amount,
        cb.description
    FROM cash_book cb
    LEFT JOIN adf_system.cash_accounts ca ON cb.cash_account_id = ca.id
    WHERE cb.transaction_type = 'income'
    ORDER BY cb.id
");
$stmt->execute();
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pettyCashIncome = 0;
$modalOwnerIncome = 0;

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Transaction ID</th><th>Date</th><th>Account ID</th><th>Account Name</th><th>Type</th><th>Amount</th><th>Description</th></tr>";

foreach ($incomes as $inc) {
    $highlight = '';
    if ($inc['account_type'] === 'cash') {
        $pettyCashIncome += $inc['amount'];
        $highlight = 'background: #d1fae5;'; // Green for Petty Cash
    } elseif ($inc['account_type'] === 'owner_capital') {
        $modalOwnerIncome += $inc['amount'];
        $highlight = 'background: #fef3c7;'; // Yellow for Modal Owner
    }
    
    echo "<tr style='$highlight'>";
    echo "<td>{$inc['id']}</td>";
    echo "<td>{$inc['transaction_date']}</td>";
    echo "<td>{$inc['cash_account_id']}</td>";
    echo "<td>{$inc['account_name']}</td>";
    echo "<td><strong>{$inc['account_type']}</strong></td>";
    echo "<td style='text-align: right;'>" . number_format($inc['amount']) . "</td>";
    echo "<td>{$inc['description']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<h3>📊 Income Summary:</h3>";
echo "<div style='padding: 15px; background: #f0f9ff; border-left: 4px solid #3b82f6;'>";
echo "<p><strong>Petty Cash Income (Green):</strong> <span style='color: #059669; font-size: 20px; font-weight: bold;'>" . number_format($pettyCashIncome) . "</span></p>";
echo "<p><strong>Modal Owner Income (Yellow):</strong> <span style='color: #d97706; font-size: 20px; font-weight: bold;'>" . number_format($modalOwnerIncome) . "</span></p>";
echo "<p><strong>Hotel Revenue (must be):</strong> <span style='color: #2563eb; font-size: 20px; font-weight: bold;'>" . number_format($pettyCashIncome) . "</span></p>";
echo "</div>";

// Get all expense transactions
echo "<hr>";
echo "<h3>💸 All Expense Transactions:</h3>";
$stmt = $businessDb->prepare("
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.cash_account_id,
        ca.account_name,
        ca.account_type,
        cb.amount,
        cb.description
    FROM cash_book cb
    LEFT JOIN adf_system.cash_accounts ca ON cb.cash_account_id = ca.id
    WHERE cb.transaction_type = 'expense'
    ORDER BY cb.id DESC
    LIMIT 20
");
$stmt->execute();
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 12px;'>";
echo "<tr><th>ID</th><th>Date</th><th>Account</th><th>Type</th><th>Amount</th><th>Description</th></tr>";

$totalExpense = 0;
foreach ($expenses as $exp) {
    $totalExpense += $exp['amount'];
    $highlight = strpos($exp['description'], '[AUTO:') !== false ? 'background: #fff3cd;' : '';
    
    echo "<tr style='$highlight'>";
    echo "<td>{$exp['id']}</td>";
    echo "<td>{$exp['transaction_date']}</td>";
    echo "<td>{$exp['account_name']}</td>";
    echo "<td>{$exp['account_type']}</td>";
    echo "<td style='text-align: right;'>" . number_format($exp['amount']) . "</td>";
    echo "<td style='max-width: 400px;'>{$exp['description']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p><strong>Total Expense (last 20):</strong> " . number_format($totalExpense) . "</p>";

echo "<hr>";
echo "<h3>🔧 PROBLEM DIAGNOSIS:</h3>";
if ($pettyCashIncome == 0) {
    echo "<div style='padding: 15px; background: #fee; border-left: 4px solid #f00; margin: 10px 0;'>";
    echo "<p style='color: red; font-weight: bold;'>❌ MASALAH: Tidak ada transaksi income di Petty Cash!</p>";
    echo "<p>Kemungkinan: Transaksi yang dihapus adalah transaksi asli Petty Cash, bukan duplikat.</p>";
    echo "<p><strong>SOLUSI:</strong> Perlu restore transaksi income Petty Cash yang terhapus.</p>";
    echo "</div>";
    
    // Check if we need to add back Petty Cash income
    echo "<h4>💡 Restore Petty Cash Income?</h4>";
    echo "<p>Jika sebelumnya ada income 500,000 di Petty Cash yang terhapus:</p>";
    echo "<form method='POST'>";
    echo "<button type='submit' name='restore_petty_cash' style='padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;'>✅ Restore Petty Cash Income 500,000</button>";
    echo "</form>";
} else {
    echo "<div style='padding: 15px; background: #d4edda; border-left: 4px solid #28a745; margin: 10px 0;'>";
    echo "<p style='color: green; font-weight: bold;'>✅ Petty Cash Income ada: " . number_format($pettyCashIncome) . "</p>";
    echo "</div>";
}

// Handle restore
if (isset($_POST['restore_petty_cash'])) {
    // Get Petty Cash account ID
    if (!empty($pettyCashAccounts)) {
        $pettyCashId = $pettyCashAccounts[0];
        
        // Insert income to cash_book
        $stmt = $businessDb->prepare("
            INSERT INTO cash_book 
            (transaction_date, transaction_time, cash_account_id, category_id, division_id, transaction_type, amount, description, created_by, created_at)
            VALUES (CURDATE(), CURTIME(), ?, 1, 1, 'income', 500000, 'Income dari tamu (restored)', 1, NOW())
        ");
        $stmt->execute([$pettyCashId]);
        $transactionId = $businessDb->lastInsertId();
        
        // Insert to cash_account_transactions
        $stmt = $masterDb->prepare("
            INSERT INTO cash_account_transactions 
            (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, created_by) 
            VALUES (?, ?, CURDATE(), 'Income dari tamu (restored)', 500000, 'income', 1)
        ");
        $stmt->execute([$pettyCashId, $transactionId]);
        
        // Update current_balance
        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + 500000 WHERE id = ?");
        $stmt->execute([$pettyCashId]);
        
        echo "<div style='padding: 15px; background: #d4edda; color: #155724; margin: 10px 0; border-radius: 5px;'>";
        echo "✅ Petty Cash income 500,000 berhasil di-restore!<br>";
        echo "Transaction ID: $transactionId<br>";
        echo "Account ID: $pettyCashId";
        echo "</div>";
        echo "<script>setTimeout(function(){ window.location.href = 'check-remaining-transactions.php'; }, 2000);</script>";
    }
}

echo "<hr>";
echo "<p><a href='debug-report-balance.php'>🔍 Debug Balance</a> | <a href='modules/reports/daily.php'>📊 View Report</a></p>";
?>
