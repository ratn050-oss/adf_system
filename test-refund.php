<?php
/**
 * Test Refund Logic - Diagnosa kenapa refund tidak mengurangi saldo
 * http://localhost:8081/adf_system/test-refund.php
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

echo "<pre style='font-family: monospace; background: #1a1a2e; color: #0f0; padding: 20px;'>\n";
echo "=== REFUND DIAGNOSTIC TEST ===\n\n";

// Test koneksi ke master DB
$masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
echo "1. MASTER_DB_NAME: {$masterDbName}\n";

try {
    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "2. Master DB Connection: ✅ OK\n";
} catch (Exception $e) {
    echo "2. Master DB Connection: ❌ FAILED - " . $e->getMessage() . "\n";
    exit;
}

// Get cash account for business_id = 1
$businessId = 1;
$stmt = $masterPdo->prepare("
    SELECT id, account_name, current_balance, account_type
    FROM cash_accounts 
    WHERE business_id = ? AND account_type IN ('cash', 'owner_capital')
    ORDER BY current_balance DESC
    LIMIT 1
");
$stmt->execute([$businessId]);
$cashAccount = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cashAccount) {
    echo "3. Cash Account Found: ✅\n";
    echo "   - ID: {$cashAccount['id']}\n";
    echo "   - Name: {$cashAccount['account_name']}\n";
    echo "   - Type: {$cashAccount['account_type']}\n";
    echo "   - Current Balance: Rp " . number_format($cashAccount['current_balance'], 0, ',', '.') . "\n\n";
} else {
    echo "3. Cash Account: ❌ NOT FOUND\n";
    exit;
}

// Test update balance
$testAmount = 100000;
$oldBalance = $cashAccount['current_balance'];
$expectedBalance = $oldBalance - $testAmount;

echo "4. Testing Balance Update...\n";
echo "   - Old Balance: Rp " . number_format($oldBalance, 0, ',', '.') . "\n";
echo "   - Test Deduction: Rp " . number_format($testAmount, 0, ',', '.') . "\n";
echo "   - Expected After: Rp " . number_format($expectedBalance, 0, ',', '.') . "\n\n";

// Do the update
$updateStmt = $masterPdo->prepare("
    UPDATE cash_accounts 
    SET current_balance = current_balance - ?
    WHERE id = ?
");
$result = $updateStmt->execute([$testAmount, $cashAccount['id']]);
$rowsAffected = $updateStmt->rowCount();

echo "5. UPDATE Execution:\n";
echo "   - PDO Result: " . ($result ? '✅ true' : '❌ false') . "\n";
echo "   - Rows Affected: {$rowsAffected}\n";

// Verify
$verifyStmt = $masterPdo->prepare("SELECT current_balance FROM cash_accounts WHERE id = ?");
$verifyStmt->execute([$cashAccount['id']]);
$newBalance = $verifyStmt->fetchColumn();

echo "   - New Balance: Rp " . number_format($newBalance, 0, ',', '.') . "\n";

if ($newBalance == $expectedBalance) {
    echo "\n6. RESULT: ✅ UPDATE WORKS CORRECTLY!\n";
    
    // Restore balance
    $restoreStmt = $masterPdo->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?");
    $restoreStmt->execute([$oldBalance, $cashAccount['id']]);
    echo "   (Balance restored to original)\n";
} else {
    echo "\n6. RESULT: ❌ UPDATE FAILED - Balance mismatch!\n";
    echo "   Expected: {$expectedBalance}, Got: {$newBalance}\n";
}

// Check recent cash_book entries for refunds
echo "\n=== RECENT CASH_BOOK EXPENSE ENTRIES ===\n";
$db = Database::getInstance();
$businessPdo = $db->getConnection();

$txnStmt = $businessPdo->query("
    SELECT id, transaction_date, transaction_type, amount, description, cash_account_id
    FROM cash_book 
    WHERE transaction_type = 'expense'
    ORDER BY id DESC 
    LIMIT 5
");
$transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($transactions)) {
    echo "No expense transactions found.\n";
} else {
    foreach ($transactions as $txn) {
        echo "ID: {$txn['id']} | Date: {$txn['transaction_date']} | Amount: Rp " . number_format($txn['amount'], 0, ',', '.') . "\n";
        echo "  Desc: " . substr($txn['description'], 0, 60) . "...\n";
        echo "  Account ID: {$txn['cash_account_id']}\n\n";
    }
}

echo "\n=== END TEST ===\n";
echo "</pre>";
?>
