<?php
/**
 * DEBUG TOOL - Check Account Balances dan Smart Logic
 * http://localhost:8081/adf_system/debug-balances.php
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
try {
    $masterDb = new PDO('mysql:host=localhost;dbname=adf_system;charset=utf8mb4', 'root', '');
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessDb = new PDO('mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4', 'root', '');
    $businessDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$businessId = 1; // narayana-hotel

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Debug Balances - Smart Logic</title>
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f5f6fa; }
.box { padding: 20px; margin: 20px 0; border-radius: 8px; background: white; }
.box-green { border-left: 4px solid #28a745; }
.box-blue { border-left: 4px solid #007bff; }
.box-yellow { border-left: 4px solid #ffc107; }
.box-red { border-left: 4px solid #dc3545; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background: #333; color: white; font-weight: 600; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; color: #d73a49; font-size: 0.9em; }
.highlight { background: #fff3cd; font-weight: 700; }
pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 0.85em; line-height: 1.5; }
</style>
</head>
<body>

<h1>🔍 Debug Balances - Smart Logic Checker</h1>
<hr>

<?php
// Get all cash accounts
$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY account_type, id");
$stmt->execute([$businessId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='box box-blue'>";
echo "<h2>💰 Current Account Balances (Master DB)</h2>";
echo "<table>";
echo "<tr><th>ID</th><th>Account Name</th><th>Type</th><th>Current Balance</th><th>Status</th></tr>";

$pettyCashAccounts = [];
$modalOwnerAccounts = [];
$bankAccounts = [];

foreach ($accounts as $acc) {
    $balanceClass = $acc['current_balance'] < 0 ? 'style="color: red; font-weight: 700;"' : '';
    $statusIcon = $acc['current_balance'] >= 100000 ? '✅ OK' : ($acc['current_balance'] > 0 ? '⚠️ Low' : '🚫 Empty');
    
    echo "<tr>";
    echo "<td>{$acc['id']}</td>";
    echo "<td><strong>{$acc['account_name']}</strong></td>";
    echo "<td><code>{$acc['account_type']}</code></td>";
    echo "<td {$balanceClass}>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</td>";
    echo "<td>{$statusIcon}</td>";
    echo "</tr>";
    
    if ($acc['account_type'] === 'cash') {
        $pettyCashAccounts[] = $acc;
    } elseif ($acc['account_type'] === 'owner_capital') {
        $modalOwnerAccounts[] = $acc;
    } elseif ($acc['account_type'] === 'bank') {
        $bankAccounts[] = $acc;
    }
}

echo "</table>";

// Calculate totals
$totalPettyCash = array_sum(array_column($pettyCashAccounts, 'current_balance'));
$totalModalOwner = array_sum(array_column($modalOwnerAccounts, 'current_balance'));
$totalOperational = $totalPettyCash + $totalModalOwner;

echo "<h3>📊 Summary:</h3>";
echo "<ul>";
echo "<li><strong>Petty Cash Total:</strong> Rp " . number_format($totalPettyCash, 0, ',', '.') . "</li>";
echo "<li><strong>Modal Owner Total:</strong> Rp " . number_format($totalModalOwner, 0, ',', '.') . "</li>";
echo "<li class='highlight'><strong>Total Kas Operasional:</strong> Rp " . number_format($totalOperational, 0, ',', '.') . "</li>";
echo "</ul>";
echo "</div>";

// Monthly Closing Information
echo "<div class='box box-blue'>";
echo "<h2>🔄 Monthly Closing System</h2>";

// Check if monthly closing tables exist
$monthlyTablesExist = false;
try {
    $stmt = $businessDb->prepare("SHOW TABLES LIKE 'monthly_archives'");
    $stmt->execute();
    $monthlyTablesExist = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Tables don't exist
}

if ($monthlyTablesExist) {
    // Get last monthly closing
    $stmt = $businessDb->prepare("
        SELECT * FROM monthly_archives 
        WHERE business_id = ? 
        ORDER BY archive_month DESC 
        LIMIT 1
    ");
    $stmt->execute([$businessId]);
    $lastClosing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastClosing) {
        echo "<p>📅 <strong>Last Monthly Closing:</strong> " . date('F Y', strtotime($lastClosing['archive_month'] . '-01')) . "</p>";
        echo "<p>💰 <strong>Profit:</strong> Rp " . number_format($lastClosing['monthly_profit'], 0, ',', '.') . "</p>";
        echo "<p>🔄 <strong>Transferred:</strong> Rp " . number_format($lastClosing['excess_transferred'], 0, ',', '.') . "</p>";
        echo "<p>⚡ <strong>Current Balance adalah carry-forward dari closing terakhir</strong></p>";
    } else {
        echo "<p>⚠️ Belum ada monthly closing yang dilakukan</p>";
        echo "<p>💡 Sistem siap untuk monthly closing pertama</p>";
    }
    
    echo "<p><strong>Recommended Monthly Closing:</strong></p>";
    echo "<ul>";
    echo "<li>Minimum Operational: Rp 500.000</li>";
    echo "<li>Excess Transfer: Rp " . number_format(max(0, $totalOperational - 500000), 0, ',', '.') . "</li>";
    echo "<li>Keep for Operation: Rp " . number_format(min($totalOperational, 500000), 0, ',', '.') . "</li>";
    echo "</ul>";
    
    echo "<a href='modules/admin/monthly-closing.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #f59e0b; color: white; text-decoration: none; border-radius: 6px;'>🔄 Process Monthly Closing</a>";
} else {
    echo "<p>⚠️ Monthly Closing System belum disetup</p>";
    echo "<p>💡 Setup monthly closing untuk reset bulanan otomatis</p>";
    echo "<a href='setup-monthly-closing.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px;'>⚙️ Setup Monthly Closing</a>";
}

echo "</div>";

// Recent transactions from cash_book
$stmt = $businessDb->prepare("
    SELECT cb.*, ca.account_name, ca.account_type 
    FROM cash_book cb
    LEFT JOIN adf_system.cash_accounts ca ON cb.cash_account_id = ca.id
    ORDER BY cb.id DESC 
    LIMIT 10
");
$stmt->execute();
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='box box-green'>";
echo "<h2>📝 Last 10 Transactions (Business DB - cash_book)</h2>";
echo "<table>";
echo "<tr><th>ID</th><th>Date/Time</th><th>Type</th><th>Amount</th><th>Account</th><th>Description</th></tr>";

foreach ($recentTransactions as $txn) {
    $typeColor = $txn['transaction_type'] === 'income' ? 'style="color: green;"' : 'style="color: red;"';
    $typeIcon = $txn['transaction_type'] === 'income' ? '📈' : '📉';
    $autoSwitched = strpos($txn['description'], '[AUTO:') !== false;
    
    echo "<tr" . ($autoSwitched ? " class='highlight'" : "") . ">";
    echo "<td>{$txn['id']}</td>";
    echo "<td>{$txn['transaction_date']} {$txn['transaction_time']}</td>";
    echo "<td {$typeColor}>{$typeIcon} {$txn['transaction_type']}</td>";
    echo "<td>Rp " . number_format($txn['amount'], 0, ',', '.') . "</td>";
    echo "<td><code>{$txn['account_type']}</code> {$txn['account_name']}</td>";
    echo "<td>" . htmlspecialchars($txn['description']) . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "<p><small>⚡ <strong>Highlighted rows</strong> = Auto-switched dari Petty Cash ke Modal Owner</small></p>";
echo "</div>";

// Smart Logic Test Scenarios
echo "<div class='box box-yellow'>";
echo "<h2>🧪 Smart Logic Test Scenarios</h2>";

$pettyCashBalance = $totalPettyCash;
$modalOwnerBalance = $totalModalOwner;

echo "<h3>Current Status:</h3>";
echo "<ul>";
echo "<li>Petty Cash Balance: <strong>Rp " . number_format($pettyCashBalance, 0, ',', '.') . "</strong></li>";
echo "<li>Modal Owner Balance: <strong>Rp " . number_format($modalOwnerBalance, 0, ',', '.') . "</strong></li>";
echo "</ul>";

echo "<h3>Test Scenarios:</h3>";

// Scenario 1: Normal Petty Cash
$testAmount1 = 100000;
if ($pettyCashBalance >= $testAmount1) {
    echo "<p>✅ <strong>Scenario 1:</strong> Pengeluaran Rp " . number_format($testAmount1, 0, ',', '.') . " → Pilih Petty Cash → <span style='color: green;'>OK, cukup saldo</span></p>";
} else {
    echo "<p>⚡ <strong>Scenario 1:</strong> Pengeluaran Rp " . number_format($testAmount1, 0, ',', '.') . " → Pilih Petty Cash → <span style='color: orange;'>AUTO-SWITCH ke Modal Owner</span></p>";
}

// Scenario 2: Petty Cash habis
$testAmount2 = $pettyCashBalance + 100000;
echo "<p>⚡ <strong>Scenario 2:</strong> Pengeluaran Rp " . number_format($testAmount2, 0, ',', '.') . " → Pilih Petty Cash → <span style='color: orange;'>AUTO-SWITCH ke Modal Owner (Petty Cash tidak cukup)</span></p>";

if ($modalOwnerBalance >= $testAmount2) {
    echo "<p style='margin-left: 30px;'>✅ Modal Owner cukup untuk cover</p>";
} else {
    echo "<p style='margin-left: 30px;'>⚠️ Modal Owner juga tidak cukup, akan minus (need owner to add modal)</p>";
}

echo "</div>";

// Check recent errors from PHP error log
echo "<div class='box box-red'>";
echo "<h2>🐛 Recent Error Logs (Last 20 lines)</h2>";
$errorLogPath = 'C:/xampp/php/logs/php_error_log';
if (file_exists($errorLogPath)) {
    $errorLines = array_slice(file($errorLogPath), -20);
    echo "<pre>";
    foreach ($errorLines as $line) {
        if (stripos($line, 'SMART LOGIC') !== false || stripos($line, 'BALANCE') !== false || stripos($line, 'AUTO') !== false) {
            echo "<strong style='color: #d97706;'>" . htmlspecialchars($line) . "</strong>";
        } else {
            echo htmlspecialchars($line);
        }
    }
    echo "</pre>";
} else {
    echo "<p>Error log file tidak ditemukan di: {$errorLogPath}</p>";
    echo "<p>Check di: <code>phpinfo()</code> untuk lokasi error_log yang benar</p>";
}
echo "</div>";

?>

<hr>
<p><a href="index.php">← Back to Dashboard</a> | <a href="modules/cashbook/add.php">Input Transaksi</a> | <a href="javascript:location.reload()">🔄 Refresh</a></p>

</body>
</html>
