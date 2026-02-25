<?php
/**
 * Debug Script for HOSTING - Check Modal Owner transactions
 * Upload to: public_html/check-modal-owner-hosting.php
 * Access via: https://adfsystem.online/check-modal-owner-hosting.php
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

// Use hosting DB credentials (auto-detected by config.php)
$masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName, DB_USER, DB_PASS);
$masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$thisMonth = '2026-02';

echo "<h2>Modal Owner Debug - February 2026</h2>";

// Get active business
$businessId = getMasterBusinessId();

echo "<p><strong>Checking business_id: $businessId</strong></p>";

// Get owner_capital accounts for this business
$stmt = $masterPdo->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
$stmt->execute([$businessId]);
$capitalAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Owner Capital Accounts:</h3>";
echo "<pre>";
print_r($capitalAccounts);
echo "</pre>";

$capitalIds = array_column($capitalAccounts, 'id');

if (!empty($capitalIds)) {
    // Get business database name
    $bizStmt = $masterPdo->prepare("SELECT database_name FROM businesses WHERE id = ?");
    $bizStmt->execute([$businessId]);
    $bizData = $bizStmt->fetch(PDO::FETCH_ASSOC);
    $bizDbName = function_exists('getDbName') ? getDbName($bizData['database_name']) : $bizData['database_name'];
    
    echo "<p><strong>Business Database: $bizDbName</strong></p>";
    
    // Connect to business DB
    $bizPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $bizDbName, DB_USER, DB_PASS);
    $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all transactions for owner_capital accounts this month
    $ph = implode(',', array_fill(0, count($capitalIds), '?'));
    $stmt = $bizPdo->prepare("
        SELECT id, transaction_date, transaction_type, amount, cash_account_id, category_id, description, created_at
        FROM cash_book 
        WHERE cash_account_id IN ($ph)
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ORDER BY transaction_date, id
    ");
    $stmt->execute(array_merge($capitalIds, [$thisMonth]));
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Transactions (Total: " . count($txns) . "):</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Date</th><th>Type</th><th>Amount</th><th>Account ID</th><th>Category</th><th>Description</th><th>Created At</th></tr>";
    
    $totalIncome = 0;
    $totalExpense = 0;
    
    foreach ($txns as $t) {
        $amt = number_format($t['amount'], 0, ',', '.');
        $type = $t['transaction_type'];
        $color = $type == 'income' ? 'green' : 'red';
        
        echo "<tr>";
        echo "<td>{$t['id']}</td>";
        echo "<td>{$t['transaction_date']}</td>";
        echo "<td style='color: $color; font-weight: bold;'>{$type}</td>";
        echo "<td style='text-align: right;'>Rp $amt</td>";
        echo "<td>{$t['cash_account_id']}</td>";
        echo "<td>{$t['category_id']}</td>";
        echo "<td>{$t['description']}</td>";
        echo "<td style='font-size: 0.8em;'>{$t['created_at']}</td>";
        echo "</tr>";
        
        if ($type == 'income') $totalIncome += $t['amount'];
        else $totalExpense += $t['amount'];
    }
    
    echo "</table>";
    
    $balance = $totalIncome - $totalExpense;
    
    echo "<h3>Summary:</h3>";
    echo "<p><strong style='color: green;'>Total Income (Setoran): Rp " . number_format($totalIncome, 0, ',', '.') . "</strong></p>";
    echo "<p><strong style='color: red;'>Total Expense (Digunakan): Rp " . number_format($totalExpense, 0, ',', '.') . "</strong></p>";
    echo "<p><strong>Balance (Sisa): Rp " . number_format($balance, 0, ',', '.') . "</strong></p>";
    
    echo "<hr>";
    echo "<p><em>Modal Owner card di dashboard menampilkan: <strong>Total Income (Setoran Owner)</strong></em></p>";
    echo "<p><em>Jika nilai salah, cek apakah ada transaksi duplikat atau transaksi yang seharusnya bukan owner_capital.</em></p>";
} else {
    echo "<p style='color: red;'><strong>No owner_capital accounts found for this business!</strong></p>";
}
?>
