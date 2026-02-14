<?php
/**
 * Setup Proper Account Structure - OPTIMIZED
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_ACCESS', true);
require_once 'config/config.php';

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessId = $businessMapping[ACTIVE_BUSINESS_ID] ?? 1;
    
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Account Structure</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            padding: 30px;
            background: #f5f5f5;
            max-width: 1200px;
            margin: 0 auto;
        }
        h1, h2, h3 { color: #333; }
        table { background: white; margin: 10px 0; border-collapse: collapse; width: 100%; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th { background: #f0f0f0; }
        code { background: #f4f4f4; padding: 3px 8px; border-radius: 3px; font-weight: bold; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn-danger { background: #f44336; color: white; }
        .btn-success { background: #4caf50; color: white; padding: 15px 30px; font-size: 16px; font-weight: bold; }
        .alert { padding: 15px; margin: 15px 0; border-left: 4px solid; }
        .alert-success { background: #d4edda; border-color: #28a745; }
        .alert-info { background: #d1ecf1; border-color: #17a2b8; }
        .box { padding: 20px; margin: 20px 0; border-left: 4px solid; }
        .box-blue { background: #e3f2fd; border-color: #2196f3; }
        .box-yellow { background: #fff3cd; border-color: #ffc107; }
        .box-green { background: #e8f5e9; border-color: #4caf50; }
    </style>
</head>
<body>
<?php


// Process delete FIRST (before showing table)
if (isset($_POST['delete_account'])) {
    $deleteId = (int)$_POST['delete_account'];
    $stmt = $masterDb->prepare("DELETE FROM cash_accounts WHERE id = ? AND business_id = ?");
    $stmt->execute([$deleteId, $businessId]);
    echo "<div class='alert alert-success'>✅ Account deleted! <a href='setup-account-structure.php'>Refresh page</a></div>";
}

// Process create FIRST
if (isset($_POST['action']) && $_POST['action'] === 'create_accounts') {
    echo "<h2>Creating Accounts...</h2>";
    
    $accounts = [
        ['account_name' => 'Petty Cash', 'account_type' => 'cash', 'description' => 'Pembayaran cash dari tamu', 'is_default' => 1],
        ['account_name' => 'Bank', 'account_type' => 'bank', 'description' => 'Pembayaran transfer dari tamu', 'is_default' => 0],
        ['account_name' => 'Kas Modal Owner', 'account_type' => 'owner_capital', 'description' => 'Modal dari owner untuk operasional', 'is_default' => 0]
    ];
    
    foreach ($accounts as $acc) {
        try {
            $stmt = $masterDb->prepare("INSERT INTO cash_accounts 
                (business_id, account_name, account_type, current_balance, is_default_account, is_active, description, created_at) 
                VALUES (?, ?, ?, 0, ?, 1, ?, NOW())");
            $stmt->execute([$businessId, $acc['account_name'], $acc['account_type'], $acc['is_default'], $acc['description']]);
            echo "<div class='alert alert-success'>✅ Created: <strong>{$acc['account_name']}</strong></div>";
        } catch (Exception $e) {
            echo "<div class='alert' style='background:#f8d7da;border-color:#f44336;'>❌ Error: " . $e->getMessage() . "</div>";
        }
    }
    
    echo "<div class='alert alert-info'><h3>✅ Setup Complete!</h3>
    <p><strong>Next Steps:</strong></p>
    <ol>
        <li><a href='setup-account-structure.php'>Refresh halaman ini</a></li>
        <li><a href='modules/cashbook/add.php'>Input transaksi</a></li>
        <li><a href='index.php'>Lihat Dashboard</a></li>
    </ol></div>";
}

echo "<h1>🏨 Setup Account Structure</h1><hr>";

// Show current accounts
$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY id");
$stmt->execute([$businessId]);
$currentAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Current Accounts:</h2>";
echo "<table><tr><th>ID</th><th>Name</th><th>Type</th><th>Balance</th><th>Action</th></tr>";

foreach ($currentAccounts as $acc) {
    echo "<tr>
        <td>{$acc['id']}</td>
        <td><strong>{$acc['account_name']}</strong></td>
        <td><code>{$acc['account_type']}</code></td>
        <td>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</td>
        <td>
            <form method='POST' style='display:inline;'>
                <input type='hidden' name='delete_account' value='{$acc['id']}'>
                <button type='submit' class='btn btn-danger' onclick=\"return confirm('Delete {$acc['account_name']}?')\">Delete</button>
            </form>
        </td>
    </tr>";
}

echo "</table>";
?>

<hr>
<h2>📋 Recommended Account Setup:</h2>

<div class="box box-blue">
<h3>3 Account yang dibutuhkan:</h3>
<table>
<tr><th>Account Name</th><th>Type</th><th>Purpose</th><th>Behavior</th></tr>
<tr style="background:#fff3cd;">
    <td><strong>Petty Cash</strong></td>
    <td><code>cash</code></td>
    <td>💰 Pembayaran CASH dari tamu</td>
    <td>✅ Masuk Revenue<br>✅ Bisa pakai operasional</td>
</tr>
<tr style="background:#d1ecf1;">
    <td><strong>Bank</strong></td>
    <td><code>bank</code></td>
    <td>🏦 Pembayaran TRANSFER dari tamu</td>
    <td>✅ Masuk Revenue<br>⚠️ Di bank saja</td>
</tr>
<tr style="background:#d4edda;">
    <td><strong>Kas Modal Owner</strong></td>
    <td><code>owner_capital</code></td>
    <td>💵 Modal dari owner</td>
    <td>❌ TIDAK masuk Revenue<br>✅ Bisa operasional</td>
</tr>
</table>
</div>

<div class="box box-yellow">
<h3>💡 Logika Bisnis:</h3>
<ul style="line-height:2;">
<li><strong>Total Revenue Hotel:</strong> Petty Cash + Bank</li>
<li><strong>Total Kas Operasional:</strong> Petty Cash + Kas Modal Owner</li>
<li><strong>Dashboard Owner:</strong> Revenue - Modal Owner = Hasil Bersih</li>
</ul>
</div>

<hr>
<h2>🔧 Auto Setup Accounts:</h2>

<form method="POST">
<div class="box box-green">
<p><strong>Akan membuat 3 account:</strong></p>
<ol>
<li>Petty Cash (type: cash)</li>
<li>Bank (type: bank)</li>
<li>Kas Modal Owner (type: owner_capital)</li>
</ol>

<label style="display:block;margin:15px 0;">
<input type="checkbox" name="confirm" required> 
Saya mengerti dan ingin setup 3 account ini
</label>

<button type="submit" name="action" value="create_accounts" class="btn btn-success">
✅ CREATE 3 ACCOUNTS NOW
</button>
</div>
</form>

</body>
</html>
