<?php
/**
 * Manual Fix: Jalankan Step 3 & 4 yang belum complete
 */
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$db = Database::getInstance();

echo "🔧 MANUAL FIX - Step 3 & 4\n";
echo "================================\n\n";

try {
    // STEP 3: Add cash_account_id column ke cash_book
    echo "📋 STEP 3: Adding cash_account_id column to cash_book...\n";
    
    try {
        $db->exec("ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` int(11) DEFAULT NULL");
        echo "✅ Column added to cash_book\n";
    } catch (Exception $e) {
        echo "ℹ️  Column exists or error: " . $e->getMessage() . "\n";
    }
    
    // STEP 4: Create default accounts
    echo "\n💾 STEP 4: Creating default cash accounts...\n";
    
    $businesses = $db->fetchAll("SELECT id, business_name FROM businesses WHERE is_active = 1");
    
    if (empty($businesses)) {
        echo "❌ No active businesses found!\n";
    } else {
        foreach ($businesses as $biz) {
            // Check if already exists
            $existing = $db->fetchOne(
                "SELECT COUNT(id) as cnt FROM cash_accounts WHERE business_id = ?",
                [$biz['id']]
            );
            
            if ($existing['cnt'] == 0) {
                // Create 3 default accounts
                $db->insert('cash_accounts', [
                    'business_id' => $biz['id'],
                    'account_name' => 'Kas Operasional',
                    'account_type' => 'cash',
                    'is_default_account' => 1,
                    'description' => 'Kas untuk pendapatan operasional',
                    'is_active' => 1
                ]);
                
                $db->insert('cash_accounts', [
                    'business_id' => $biz['id'],
                    'account_name' => 'Kas Modal Owner',
                    'account_type' => 'owner_capital',
                    'description' => 'Dana dari pemilik untuk kebutuhan operasional harian',
                    'is_active' => 1
                ]);
                
                $db->insert('cash_accounts', [
                    'business_id' => $biz['id'],
                    'account_name' => 'Bank',
                    'account_type' => 'bank',
                    'description' => 'Rekening bank utama bisnis',
                    'is_active' => 1
                ]);
                
                echo "✅ {$biz['business_name']}: 3 accounts created\n";
            } else {
                echo "ℹ️  {$biz['business_name']}: Accounts exist (skip)\n";
            }
        }
    }
    
    // VERIFY
    echo "\n📊 VERIFICATION:\n";
    echo "================================\n";
    
    $total = $db->fetchOne("SELECT COUNT(id) as cnt FROM cash_accounts");
    echo "✓ Total cash_accounts: " . $total['cnt'] . "\n";
    
    $columns = $db->fetchAll("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    echo "✓ cash_account_id column: " . (count($columns) > 0 ? 'ADA ✓' : 'TIDAK ADA ✗') . "\n";
    
    echo "\n✅ FIX SELESAI!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    var_dump($e);
}
?>
