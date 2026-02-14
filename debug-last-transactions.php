<?php
/**
 * Debug Last Transactions - Check cash_account_id
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

// Connect to master DB
$masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$businessMapping = [
    'narayana-hotel' => 1,
    'bens-cafe' => 2
];

$businessId = $businessMapping[ACTIVE_BUSINESS_ID] ?? 1;

echo "<h2>Last 10 Transactions - Cash Account Mapping</h2>";

// Get last transactions
$transactions = $db->fetchAll("
    SELECT id, transaction_date, description, amount, transaction_type, cash_account_id, created_at
    FROM cash_book 
    ORDER BY id DESC 
    LIMIT 10
");

if (empty($transactions)) {
    echo "<p style='color: red; font-weight: bold;'>No transactions found!</p>";
    exit;
}

// Get all cash accounts
$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ?");
$stmt->execute([$businessId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$accountMap = [];
foreach ($accounts as $acc) {
    $accountMap[$acc['id']] = $acc;
}

echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%; font-family: monospace;'>";
echo "<tr style='background: #f0f0f0;'>
        <th>ID</th>
        <th>Date</th>
        <th>Description</th>
        <th>Amount</th>
        <th>Type</th>
        <th>cash_account_id</th>
        <th>Account Name</th>
        <th>Account Type</th>
        <th>Status</th>
      </tr>";

foreach ($transactions as $tx) {
    $accountId = $tx['cash_account_id'];
    $accountInfo = '';
    $accountType = '';
    $status = '';
    $bgColor = '#fff';
    
    if ($accountId && isset($accountMap[$accountId])) {
        $account = $accountMap[$accountId];
        $accountInfo = $account['account_name'];
        $accountType = $account['account_type'];
        
        // Check if it's problematic
        if ($tx['transaction_type'] === 'income') {
            if ($accountType === 'owner_capital') {
                $status = '✅ CORRECT - Excluded from hotel revenue';
                $bgColor = '#d4edda';
            } elseif ($accountType === 'cash' || $accountType === 'bank') {
                $status = '⚠️ INCLUDED in hotel revenue';
                $bgColor = '#fff3cd';
            }
        } else if ($tx['transaction_type'] === 'expense') {
            if ($accountType === 'owner_capital') {
                $status = '✅ CORRECT - Excluded from hotel expense';
                $bgColor = '#d4edda';
            } elseif ($accountType === 'cash' || $accountType === 'bank') {
                $status = '⚠️ INCLUDED in hotel expense';
                $bgColor = '#fff3cd';
            }
        }
    } else {
        $accountInfo = '(NULL - No account)';
        $accountType = '-';
        $status = '⚠️ No account selected';
        $bgColor = '#ffcccc';
    }
    
    $typeColor = $tx['transaction_type'] === 'income' ? '#10b981' : '#ef4444';
    
    echo "<tr style='background: $bgColor;'>";
    echo "<td>{$tx['id']}</td>";
    echo "<td>" . date('d M Y', strtotime($tx['transaction_date'])) . "</td>";
    echo "<td>{$tx['description']}</td>";
    echo "<td style='font-weight: bold;'>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
    echo "<td style='color: $typeColor; font-weight: bold;'>{$tx['transaction_type']}</td>";
    echo "<td><strong>{$accountId}</strong></td>";
    echo "<td>{$accountInfo}</td>";
    echo "<td><code>{$accountType}</code></td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}

echo "</table>";

// Show account list
echo "<hr style='margin: 40px 0;'>";
echo "<h3>Available Cash Accounts</h3>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse; font-family: monospace;'>";
echo "<tr style='background: #f0f0f0;'>
        <th>ID</th>
        <th>Account Name</th>
        <th>Account Type</th>
        <th>Behavior</th>
      </tr>";

foreach ($accounts as $acc) {
    $behavior = '';
    if ($acc['account_type'] === 'owner_capital') {
        $behavior = '❌ EXCLUDED from hotel stats (owner capital)';
        $bgColor = '#d4edda';
    } elseif ($acc['account_type'] === 'cash') {
        $behavior = '✅ INCLUDED in hotel stats (operational cash)';
        $bgColor = '#fff3cd';
    } elseif ($acc['account_type'] === 'bank') {
        $behavior = '✅ INCLUDED in hotel stats (bank account)';
        $bgColor = '#d1ecf1';
    } else {
        $behavior = 'Other type';
        $bgColor = '#fff';
    }
    
    echo "<tr style='background: $bgColor;'>";
    echo "<td>{$acc['id']}</td>";
    echo "<td><strong>{$acc['account_name']}</strong></td>";
    echo "<td><code>{$acc['account_type']}</code></td>";
    echo "<td>{$behavior}</td>";
    echo "</tr>";
}

echo "</table>";

// PROBLEM DIAGNOSIS
echo "<hr style='margin: 40px 0;'>";
echo "<div style='background: #e3f2fd; padding: 20px; border-left: 4px solid #2196f3;'>";
echo "<h3 style='color: #1565c0; margin-top: 0;'>🔍 DIAGNOSIS:</h3>";

$problem = false;
foreach ($transactions as $tx) {
    if ($tx['transaction_type'] === 'income' && $tx['amount'] == 2500000) {
        $accountId = $tx['cash_account_id'];
        if ($accountId && isset($accountMap[$accountId])) {
            $account = $accountMap[$accountId];
            if ($account['account_type'] !== 'owner_capital') {
                $problem = true;
                echo "<p style='color: #d32f2f; font-weight: bold;'>⚠️ FOUND PROBLEM:</p>";
                echo "<ul style='line-height: 1.8;'>";
                echo "<li>Transaksi ID: <strong>{$tx['id']}</strong></li>";
                echo "<li>Amount: <strong>Rp 2.500.000</strong> (INCOME)</li>";
                echo "<li>Masuk ke Account: <strong>{$account['account_name']}</strong> (ID: {$accountId})</li>";
                echo "<li>Account Type: <code>{$account['account_type']}</code></li>";
                echo "<li><strong style='color: red;'>MASALAH: Account type bukan 'owner_capital' jadi MASUK ke pendapatan hotel!</strong></li>";
                echo "</ul>";
                
                echo "<h4>✅ SOLUSI:</h4>";
                echo "<ol style='line-height: 1.8;'>";
                echo "<li>Buka: <a href='fix-account-setup.php' style='color: #1565c0; font-weight: bold;'>Fix Account Setup Tool</a></li>";
                echo "<li>Klik tombol <strong>'FIX ACCOUNT TYPES NOW'</strong> untuk ubah account type</li>";
                echo "<li>Refresh dashboard - income Rp 2.500.000 akan hilang dari Total Pemasukan</li>";
                echo "</ol>";
            }
        }
    }
}

if (!$problem) {
    echo "<p style='color: #2e7d32; font-weight: bold;'>✅ Tidak ada masalah ditemukan dalam 10 transaksi terakhir.</p>";
}

echo "</div>";

?>

<style>
    body { 
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
        padding: 30px;
        background: #f5f5f5;
    }
    h2, h3 { color: #333; }
    table { background: white; }
    code { 
        background: #f4f4f4; 
        padding: 2px 6px; 
        border-radius: 3px; 
        font-family: 'Courier New', monospace;
        font-weight: bold;
    }
</style>
