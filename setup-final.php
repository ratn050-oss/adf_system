<?php
/**
 * FINAL FIX - Dengan struktur tabel yang benar
 */
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$pdo = Database::getInstance()->getConnection();

echo "🔧 FINAL FIX - SETUP CASH ACCOUNTS\n";
echo "==================================\n\n";

try {
    // 1. Add kolom ke cash_book
    echo "1. Adding column cash_account_id to cash_book...\n";
    try {
        $pdo->exec("ALTER TABLE cash_book ADD COLUMN cash_account_id INT(11) DEFAULT NULL");
        echo "   ✅ Column added\n";
    } catch (PDOException $e) {
        echo "   ℹ️  Column exists (skip)\n";
    }
    
    // 2. Get all businesses (tanpa filter is_active karena tidak ada)
    echo "\n2. Creating default accounts for businesses...\n";
    $stmt = $pdo->prepare("SELECT id, business_name FROM businesses");
    $stmt->execute();
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($businesses)) {
        echo "   ❌ No businesses found\n";
    } else {
        foreach ($businesses as $biz) {
            $bizId = $biz['id'];
            $bizName = $biz['business_name'];
            
            // Check existing accounts
            $check = $pdo->prepare("SELECT COUNT(id) as cnt FROM cash_accounts WHERE business_id = ?");
            $check->execute([$bizId]);
            $result = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($result['cnt'] == 0) {
                // Insert 3 default accounts
                $pdo->beginTransaction();
                
                $insert = $pdo->prepare(
                    "INSERT INTO cash_accounts (business_id, account_name, account_type, is_default_account, description, is_active) 
                     VALUES (?, ?, ?, ?, ?, 1)"
                );
                
                $insert->execute([$bizId, 'Kas Operasional', 'cash', 1, 'Kas untuk pendapatan operasional']);
                $insert->execute([$bizId, 'Kas Modal Owner', 'owner_capital', 0, 'Dana dari pemilik untuk kebutuhan operasional']);
                $insert->execute([$bizId, 'Bank', 'bank', 0, 'Rekening bank utama bisnis']);
                
                $pdo->commit();
                echo "   ✅ $bizName: 3 accounts created\n";
            } else {
                echo "   ℹ️  $bizName: Already has {$result['cnt']} accounts\n";
            }
        }
    }
    
    // 3. Final verification
    echo "\n3. Final Verification:\n";
    $count = $pdo->query("SELECT COUNT(id) as cnt FROM cash_accounts")->fetch(PDO::FETCH_ASSOC);
    echo "   ✓ Total accounts in database: " . $count['cnt'] . "\n";
    
    $col_check = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    $has_col = $col_check->rowCount() > 0;
    echo "   ✓ Column cash_account_id in cash_book: " . ($has_col ? 'ADA ✓' : 'TIDAK ADA ✗') . "\n";
    
    // Show created accounts
    echo "\n4. Created Accounts:\n";
    $accounts = $pdo->query("SELECT business_id, account_name, account_type FROM cash_accounts ORDER BY business_id, is_default_account DESC");
    $acc_list = $accounts->fetchAll(PDO::FETCH_ASSOC);
    foreach ($acc_list as $acc) {
        echo "   - {$acc['account_name']} ({$acc['account_type']}) - Business ID: {$acc['business_id']}\n";
    }
    
    echo "\n✅ SETUP COMPLETE!\n";
    echo "Database updated successfully. You can now test the cashbook form.\n";
    
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " | Line: " . $e->getLine() . "\n";
}
?>
