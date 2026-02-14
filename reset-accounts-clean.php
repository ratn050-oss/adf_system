<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
try {
    $masterDb = new PDO('mysql:host=localhost;dbname=adf_system;charset=utf8mb4', 'root', '');
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$businessId = 1; // narayana-hotel

// Process DELETE ALL
if (isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    try {
        // Step 1: Get all account IDs for this business
        $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ?");
        $stmt->execute([$businessId]);
        $accountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $deletedTransactions = 0;
        $deletedAccounts = 0;
        
        if (!empty($accountIds)) {
            // Step 2: Delete transactions that reference these accounts
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id IN ($placeholders)");
            $stmt->execute($accountIds);
            $deletedTransactions = $stmt->rowCount();
            
            // Step 3: Now safe to delete accounts
            $stmt = $masterDb->prepare("DELETE FROM cash_accounts WHERE business_id = ?");
            $stmt->execute([$businessId]);
            $deletedAccounts = $stmt->rowCount();
        }
        
        echo "<div class='box box-green'>✅ <strong>Berhasil hapus:</strong><br>
        - {$deletedTransactions} transaksi<br>
        - {$deletedAccounts} accounts<br><br>
        <a href='reset-accounts-clean.php'>Refresh page</a> atau 
        <a href='modules/cashbook/add.php'>Lihat form input</a></div>";
    } catch (Exception $e) {
        echo "<div class='box box-red'>❌ Error: " . $e->getMessage() . "</div>";
    }
}

// Process CREATE 3 ACCOUNTS
if (isset($_POST['action']) && $_POST['action'] === 'create_3') {
    try {
        $accounts = [
            ['name' => 'Petty Cash', 'type' => 'cash', 'desc' => 'Uang cash dari tamu hotel'],
            ['name' => 'Bank', 'type' => 'bank', 'desc' => 'Hasil transfer dari tamu hotel'],
            ['name' => 'Kas Modal Owner', 'type' => 'owner_capital', 'desc' => 'Modal operasional dari owner']
        ];
        
        $created = 0;
        foreach ($accounts as $acc) {
            $stmt = $masterDb->prepare("
                INSERT INTO cash_accounts (business_id, account_name, account_type, description, current_balance, created_at) 
                VALUES (?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$businessId, $acc['name'], $acc['type'], $acc['desc']]);
            $created++;
        }
        
        echo "<div class='box box-green'>✅ <strong>Berhasil create {$created} accounts!</strong><br><br>
        <a href='reset-accounts-clean.php'>Refresh page</a> | 
        <a href='modules/cashbook/add.php'>Test Input Transaksi</a> | 
        <a href='index.php'>Lihat Dashboard</a></div>";
    } catch (Exception $e) {
        echo "<div class='box box-red'>❌ Error: " . $e->getMessage() . "</div>";
    }
}

// Get current accounts
$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY id");
$stmt->execute([$businessId]);
$currentAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Reset Accounts - Clean Duplikat</title>
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f5f6fa; }
.box { padding: 20px; margin: 20px 0; border-radius: 8px; }
.box-red { background: #ffe6e6; border-left: 4px solid #dc3545; }
.box-green { background: #e6ffe6; border-left: 4px solid #28a745; }
.box-blue { background: #e6f2ff; border-left: 4px solid #007bff; }
.box-yellow { background: #fff9e6; border-left: 4px solid #ffc107; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; background: white; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background: #333; color: white; font-weight: 600; }
.btn { padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600; }
.btn-danger { background: #dc3545; color: white; }
.btn-success { background: #28a745; color: white; }
.btn:hover { opacity: 0.9; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; color: #d73a49; }
hr { border: none; border-top: 2px solid #ddd; margin: 30px 0; }
</style>
</head>
<body>

<h1>🧹 Reset Accounts - Clean Duplikat</h1>
<hr>

<h2>Current Accounts (<?= count($currentAccounts) ?>):</h2>

<?php if (count($currentAccounts) > 0): ?>
<table>
<tr><th>ID</th><th>Name</th><th>Type</th><th>Description</th><th>Balance</th></tr>
<?php foreach ($currentAccounts as $acc): ?>
<tr>
    <td><?= $acc['id'] ?></td>
    <td><strong><?= htmlspecialchars($acc['account_name']) ?></strong></td>
    <td><code><?= $acc['account_type'] ?></code></td>
    <td><?= htmlspecialchars($acc['description']) ?></td>
    <td>Rp <?= number_format($acc['current_balance'], 0, ',', '.') ?></td>
</tr>
<?php endforeach; ?>
</table>

<div class="box box-red">
<h3>⚠️ Ada Duplikat!</h3>
<p>Klik tombol di bawah untuk <strong>HAPUS SEMUA</strong> accounts + transaksi lalu create ulang yang benar (3 accounts saja).</p>
<p style="color:#dc3545;"><strong>⚠️ WARNING:</strong> Ini akan hapus semua transaksi yang terkait dengan accounts ini!</p>

<form method="POST" style="margin-top:15px;">
<button type="submit" name="action" value="delete_all" class="btn btn-danger" 
onclick="return confirm('Yakin hapus SEMUA <?= count($currentAccounts) ?> accounts + transaksinya?')">
🗑️ DELETE ALL (Accounts + Transaksi)
</button>
</form>
</div>

<?php else: ?>
<div class="box box-blue">
<p>✅ <strong>Tidak ada account.</strong> Klik tombol di bawah untuk create 3 accounts yang benar.</p>
</div>
<?php endif; ?>

<hr>

<h2>🔧 Create 3 Accounts (Clean):</h2>

<div class="box box-green">
<p><strong>Akan membuat 3 account:</strong></p>
<table>
<tr><th>Name</th><th>Type</th><th>Purpose</th></tr>
<tr style="background:#fff3cd;">
    <td><strong>Petty Cash</strong></td>
    <td><code>cash</code></td>
    <td>💰 Pembayaran CASH dari tamu → Masuk Revenue</td>
</tr>
<tr style="background:#d1ecf1;">
    <td><strong>Bank</strong></td>
    <td><code>bank</code></td>
    <td>🏦 Pembayaran TRANSFER dari tamu → Masuk Revenue</td>
</tr>
<tr style="background:#d4edda;">
    <td><strong>Kas Modal Owner</strong></td>
    <td><code>owner_capital</code></td>
    <td>💵 Modal operasional dari owner → TIDAK masuk Revenue</td>
</tr>
</table>

<form method="POST" style="margin-top:15px;">
<button type="submit" name="action" value="create_3" class="btn btn-success">
✅ CREATE 3 ACCOUNTS NOW
</button>
</form>
</div>

<hr>
<p><a href="index.php">← Back to Dashboard</a></p>

</body>
</html>
