<?php
/**
 * Simple Direct Fix - No error handling complications
 */
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

echo "🔧 DIRECT FIX\n";
echo "=======================\n\n";

try {
    // 1. Add kolom ke cash_book
    echo "1. Adding column cash_account_id to cash_book...\n";
    $sql1 = "ALTER TABLE cash_book ADD COLUMN cash_account_id INT(11) DEFAULT NULL";
    try {
        $pdo->exec($sql1);
        echo "   ✅ Column added\n";
    } catch (PDOException $e) {
        echo "   ℹ️  Column exists (skip)\n";
    }
    
    // 2. Get all businesses
    echo "\n2. Creating default accounts for businesses...\n";
    $stmt = $pdo->prepare("SELECT id, business_name FROM businesses WHERE is_active = 1");
    $stmt->execute();
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($businesses)) {
        echo "   ❌ No businesses found\n";
    } else {
        foreach ($businesses as $biz) {
            $bizId = $biz['id'];
            $bizName = $biz['business_name'];
            
            // Check existing
            $check = $pdo->prepare("SELECT COUNT(id) as cnt FROM cash_accounts WHERE business_id = ?");
            $check->execute([$bizId]);
            $result = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($result['cnt'] == 0) {
                // Insert 3 accounts
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
                echo "   ℹ️  $bizName: Already has accounts\n";
            }
        }
    }
    
    // 3. Verify
    echo "\n3. Verification...\n";
    $verify = $pdo->query("SELECT COUNT(id) as cnt FROM cash_accounts");
    $count = $verify->fetch(PDO::FETCH_ASSOC);
    echo "   ✓ Total accounts: " . $count['cnt'] . "\n";
    
    $col_check = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    $has_col = $col_check->rowCount() > 0;
    echo "   ✓ Column cash_account_id: " . ($has_col ? 'ADA ✓' : 'TIDAK ADA ✗') . "\n";
    
    echo "\n✅ SELESAI!\n";
    
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
?>
