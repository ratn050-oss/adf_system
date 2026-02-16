<?php
/**
 * SIMPLE SETUP for Monthly Closing System (Local XAMPP)
 * Create required tables for monthly reset functionality
 */

echo "<h1>🔄 Monthly Closing System Setup (Local)</h1>";

try {
    // Local XAMPP connection
    $businessDb = new PDO('mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4', 'root', '');
    $businessDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $masterDb = new PDO('mysql:host=localhost;dbname=adf_system;charset=utf8mb4', 'root', '');
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p>✅ Database connections established</p>";
    
    // 1. Create monthly_archives table in business DB
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
    
    $businessDb->exec($sql);
    echo "✅ monthly_archives table created in business DB<br>";
    
    // 2. Create monthly_carry_forward table in business DB
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
    
    $businessDb->exec($sql);
    echo "✅ monthly_carry_forward table created in business DB<br>";
    
    // 3. Update master database transaction types
    echo "<h2>3. Updating master database transaction types...</h2>";
    
    // Check current enum values
    $stmt = $masterDb->prepare("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_type'");
    $stmt->execute();
    $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo && strpos($columnInfo['Type'], 'capital_return') === false) {
        $sql = "ALTER TABLE `cash_account_transactions` 
                MODIFY COLUMN `transaction_type` ENUM('income','expense','transfer','capital_injection','capital_return','monthly_closing') NOT NULL DEFAULT 'income'";
        $masterDb->exec($sql);
        echo "✅ Master DB transaction types updated with new values<br>";
    } else {
        echo "✅ Master DB transaction types already up to date<br>";
    }
    
    echo "<h2>🎉 Monthly Closing System Setup Complete!</h2>";
    
    // Show current balances
    echo "<h3>📊 Current System Status:</h3>";
    $businessId = 1; // narayana-hotel
    
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
    
    echo "<div style='background: #d1fae5; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46; margin: 0 0 10px 0;'>✅ Setup Berhasil!</h3>";
    echo "<p style='margin: 5px 0;'><strong>Next Steps:</strong></p>";
    echo "<ol style='margin: 0; padding-left: 20px;'>";
    echo "<li><a href='modules/admin/monthly-closing.php' style='color: #065f46; font-weight: 600;'>Access Monthly Closing Page</a></li>";
    echo "<li><a href='debug-balances.php' style='color: #065f46; font-weight: 600;'>Check Debug Balances</a> - sekarang ada info Monthly Closing</li>";
    echo "<li>Set minimum operational amount (recommend: Rp 500.000)</li>";
    echo "<li>Process monthly closing untuk bulan sebelumnya</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; color: #c62828; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0;'>";
    echo "<strong>❌ Setup Error:</strong> " . $e->getMessage();
    echo "</div>";
    
    echo "<p><strong>Troubleshooting:</strong></p>";
    echo "<ul>";
    echo "<li>Pastikan XAMPP MySQL running</li>";
    echo "<li>Database adf_narayana_hotel dan adf_system harus ada</li>";
    echo "<li>User root harus punya privilege CREATE TABLE</li>";
    echo "</ul>";
}
?>

<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
    max-width: 900px; 
    margin: 2rem auto; 
    padding: 2rem;
    background: #f8fafc;
}

h1 { 
    color: #1e293b; 
    border-bottom: 3px solid #3b82f6;
    padding-bottom: 0.5rem;
    margin-bottom: 2rem;
}

h2 { 
    color: #475569; 
    border-bottom: 1px solid #cbd5e1;
    padding-bottom: 0.3rem;
    margin-top: 2rem;
}

h3 {
    color: #64748b;
}

table { 
    margin: 1rem 0; 
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    background: white;
    border-radius: 8px;
    overflow: hidden;
}

th, td { 
    padding: 12px 16px; 
    border: none;
}

th { 
    background: #475569; 
    color: white; 
    font-weight: 600; 
    text-transform: uppercase;
    font-size: 0.875rem;
}

tr:nth-child(even) { 
    background: #f8fafc; 
}

a {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}

a:hover {
    text-decoration: underline;
    color: #1d4ed8;
}

p {
    line-height: 1.6;
    color: #475569;
}

ol, ul {
    color: #475569;
    line-height: 1.6;
}
</style>