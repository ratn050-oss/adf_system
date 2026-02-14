<?php
/**
 * EMERGENCY FIX - Create Tables NOW
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<h2>🚨 EMERGENCY TABLE CREATION</h2>";
echo "<hr>";

try {
    // Connect to MASTER database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>Step 1: Creating cash_accounts table in MASTER DB (adf_system)</h3>";
    
    // DROP if exists (for clean install)
    $masterDb->exec("DROP TABLE IF EXISTS `cash_account_transactions`");
    $masterDb->exec("DROP TABLE IF EXISTS `cash_accounts`");
    echo "✅ Dropped old tables (if existed)<br>";
    
    // Create cash_accounts
    $sql1 = "
    CREATE TABLE `cash_accounts` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `business_id` int(11) NOT NULL,
      `account_name` varchar(100) NOT NULL,
      `account_type` enum('cash','bank','e-wallet','owner_capital','credit_card') NOT NULL DEFAULT 'cash',
      `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
      `is_default_account` tinyint(1) NOT NULL DEFAULT 0,
      `description` text,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `business_id` (`business_id`),
      KEY `account_type` (`account_type`),
      KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $masterDb->exec($sql1);
    echo "✅ Table cash_accounts created<br>";
    
    // Create cash_account_transactions
    $sql2 = "
    CREATE TABLE `cash_account_transactions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `cash_account_id` int(11) NOT NULL,
      `transaction_id` int(11) DEFAULT NULL,
      `transaction_date` date NOT NULL,
      `description` varchar(255) NOT NULL,
      `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
      `transaction_type` enum('income','expense','transfer','opening_balance','capital_injection') NOT NULL,
      `reference_number` varchar(50) DEFAULT NULL,
      `created_by` int(11) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `cash_account_id` (`cash_account_id`),
      KEY `transaction_date` (`transaction_date`),
      KEY `transaction_type` (`transaction_type`),
      CONSTRAINT `fk_cash_account` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $masterDb->exec($sql2);
    echo "✅ Table cash_account_transactions created<br>";
    echo "<hr>";
    
    // Step 2: Add column to business databases
    echo "<h3>Step 2: Adding cash_account_id column to business databases</h3>";
    
    $businesses = $masterDb->query("SELECT id, database_name, business_name FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($businesses as $biz) {
        try {
            $bizDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname={$biz['database_name']};charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Check if column exists
            $cols = $bizDb->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'")->fetchAll();
            
            if (empty($cols)) {
                $bizDb->exec("ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` int(11) DEFAULT NULL AFTER `category_id`");
                echo "✅ {$biz['business_name']}: Column added to cash_book<br>";
            } else {
                echo "ℹ️  {$biz['business_name']}: Column already exists<br>";
            }
        } catch (Exception $e) {
            echo "❌ {$biz['business_name']}: " . $e->getMessage() . "<br>";
        }
    }
    echo "<hr>";
    
    // Step 3: Insert default accounts
    echo "<h3>Step 3: Inserting default accounts</h3>";
    
    foreach ($businesses as $biz) {
        // Check if accounts exist
        $stmt = $masterDb->prepare("SELECT COUNT(*) as cnt FROM cash_accounts WHERE business_id = ?");
        $stmt->execute([$biz['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing['cnt'] == 0) {
            // Insert 3 default accounts
            $stmt = $masterDb->prepare("
                INSERT INTO cash_accounts (business_id, account_name, account_type, is_default_account, description, is_active) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            // 1. Kas Operasional (default)
            $stmt->execute([
                $biz['id'],
                'Kas Operasional',
                'cash',
                1,
                'Kas untuk pendapatan operasional'
            ]);
            
            // 2. Kas Modal Owner
            $stmt->execute([
                $biz['id'],
                'Kas Modal Owner',
                'owner_capital',
                1,
                'Dana dari pemilik untuk kebutuhan operasional harian'
            ]);
            
            // 3. Bank
            $stmt->execute([
                $biz['id'],
                'Bank',
                'bank',
                0,
                'Rekening bank utama bisnis'
            ]);
            
            echo "✅ {$biz['business_name']}: 3 default accounts created<br>";
        } else {
            echo "ℹ️  {$biz['business_name']}: Accounts already exist ({$existing['cnt']} accounts)<br>";
        }
    }
    
    echo "<hr>";
    echo "<h3>✅ SETUP COMPLETE!</h3>";
    
    // Verify
    $total = $masterDb->query("SELECT COUNT(*) as cnt FROM cash_accounts")->fetch(PDO::FETCH_ASSOC);
    echo "Total accounts in database: <strong>{$total['cnt']}</strong><br>";
    
    $accounts = $masterDb->query("SELECT ca.*, b.business_name FROM cash_accounts ca JOIN businesses b ON ca.business_id = b.id ORDER BY b.id, ca.account_type")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h4>Account List:</h4>";
    foreach ($accounts as $acc) {
        $default = $acc['is_default_account'] ? '⭐' : '';
        echo "- {$default} <strong>{$acc['account_name']}</strong> ({$acc['account_type']}) - {$acc['business_name']}<br>";
    }
    
    echo "<hr>";
    echo "<a href='test-all-quick.php' style='padding: 1rem 2rem; background: #10b981; color: white; text-decoration: none; border-radius: 8px; display: inline-block; margin: 1rem 0;'>Test All Features</a><br>";
    echo "<a href='index.php' style='padding: 1rem 2rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; display: inline-block; margin: 1rem 0;'>Go to Dashboard</a><br>";
    echo "<a href='modules/cashbook/add.php' style='padding: 1rem 2rem; background: #f59e0b; color: white; text-decoration: none; border-radius: 8px; display: inline-block; margin: 1rem 0;'>Test Cashbook Form</a>";
    
} catch (Exception $e) {
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
    echo $e->getMessage();
    echo "\n\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>
