<?php
/**
 * SETUP MONTHLY CLOSING SYSTEM
 * Create required tables for monthly reset functionality
 */

require_once 'config/config.php';
require_once 'config/database.php';

try {
    // Connect to business database
    $db = Database::getInstance()->getConnection();
    
    echo "<h1>🔄 Monthly Closing System Setup</h1>";
    
    // 1. Create monthly_archives table
    echo "<h2>1. Creating monthly_archives table...</h2>";
    $sql = "
    CREATE TABLE IF NOT EXISTS `monthly_archives` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `business_id` int(11) NOT NULL,
      `archive_month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
      `total_income` decimal(15,2) NOT NULL DEFAULT 0.00,
      `total_expense` decimal(15,2) NOT NULL DEFAULT 0.00,
      `monthly_profit` decimal(15,2) NOT NULL DEFAULT 0.00,
      `transaction_count` int(11) NOT NULL DEFAULT 0,
      `final_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
      `minimum_operational` decimal(15,2) NOT NULL DEFAULT 0.00,
      `excess_transferred` decimal(15,2) NOT NULL DEFAULT 0.00,
      `closing_date` datetime NOT NULL,
      `closed_by` int(11) NOT NULL,
      `notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_business_month` (`business_id`, `archive_month`),
      KEY `idx_business_month` (`business_id`, `archive_month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($sql);
    echo "✅ monthly_archives table created successfully<br>";
    
    // 2. Create monthly_carry_forward table
    echo "<h2>2. Creating monthly_carry_forward table...</h2>";
    $sql = "
    CREATE TABLE IF NOT EXISTS `monthly_carry_forward` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `business_id` int(11) NOT NULL,
      `month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
      `carry_forward_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
      `petty_cash_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
      `owner_capital_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
      `notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_business_month` (`business_id`, `month`),
      KEY `idx_business_month` (`business_id`, `month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($sql);
    echo "✅ monthly_carry_forward table created successfully<br>";
    
    // 3. Check master database for cash_account_transactions enum update
    echo "<h2>3. Updating master database transaction types...</h2>";
    
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Check current enum values
    $stmt = $masterDb->prepare("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_type'");
    $stmt->execute();
    $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo && strpos($columnInfo['Type'], 'capital_return') === false) {
        $sql = "ALTER TABLE `cash_account_transactions` 
                MODIFY COLUMN `transaction_type` ENUM('income','expense','transfer','capital_injection','capital_return','monthly_closing') NOT NULL DEFAULT 'income'";
        $masterDb->exec($sql);
        echo "✅ Master DB transaction types updated<br>";
    } else {
        echo "✅ Master DB transaction types already up to date<br>";
    }
    
    echo "<h2>🎉 Monthly Closing System Setup Complete!</h2>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ol>";
    echo "<li><a href='modules/admin/monthly-closing.php' target='_blank'>Access Monthly Closing Page</a></li>";
    echo "<li>Review current balances and set minimum operational amount</li>";
    echo "<li>Process your first monthly closing</li>";
    echo "</ol>";
    
    echo "<hr>";
    echo "<h3>📊 Current System Status:</h3>";
    
    // Show current balances
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessId = $businessMapping[ACTIVE_BUSINESS_ID] ?? 1;
    
    $stmt = $masterDb->prepare("
        SELECT account_type, account_name, current_balance 
        FROM cash_accounts 
        WHERE business_id = ? AND account_type IN ('cash', 'owner_capital')
        ORDER BY account_type
    ");
    $stmt->execute([$businessId]);
    $accounts = $stmt->fetchAll();
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>Account Type</th><th>Account Name</th><th>Current Balance</th>";
    echo "</tr>";
    
    $totalBalance = 0;
    foreach ($accounts as $acc) {
        $balance = (float)$acc['current_balance'];
        $totalBalance += $balance;
        
        echo "<tr>";
        echo "<td>{$acc['account_type']}</td>";
        echo "<td>{$acc['account_name']}</td>";
        echo "<td style='text-align: right;'>Rp " . number_format($balance, 0, ',', '.') . "</td>";
        echo "</tr>";
    }
    
    echo "<tr style='background: #e6f3ff; font-weight: bold;'>";
    echo "<td colspan='2'>TOTAL OPERATIONAL CASH</td>";
    echo "<td style='text-align: right;'>Rp " . number_format($totalBalance, 0, ',', '.') . "</td>";
    echo "</tr>";
    echo "</table>";
    
    echo "<p><strong>Ready untuk monthly closing!</strong> 🚀</p>";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; color: #c62828; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0;'>";
    echo "<strong>❌ Setup Error:</strong> " . $e->getMessage();
    echo "</div>";
    
    echo "<p><strong>Troubleshooting:</strong></p>";
    echo "<ul>";
    echo "<li>Pastikan database connection benar</li>";
    echo "<li>User MySQL harus punya privilege CREATE TABLE</li>";
    echo "<li>Check config/config.php settings</li>";
    echo "</ul>";
}
?>

<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
    max-width: 800px; 
    margin: 2rem auto; 
    padding: 2rem;
    background: #f5f5f5;
}

h1, h2 { 
    color: #333; 
    border-bottom: 2px solid #007cba;
    padding-bottom: 0.5rem;
}

table { 
    margin: 1rem 0; 
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    background: white;
    border-radius: 6px;
    overflow: hidden;
}

th, td { 
    padding: 12px 15px; 
    border: none;
}

th { 
    background: #333; 
    color: white; 
    font-weight: 600; 
}

tr:nth-child(even) { 
    background: #f9f9f9; 
}

a {
    color: #007cba;
    text-decoration: none;
    font-weight: 600;
}

a:hover {
    text-decoration: underline;
}
</style>