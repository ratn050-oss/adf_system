<?php
/**
 * Quick check for cash_accounts table and data
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<h2>🔍 Checking Cash Accounts Setup</h2>";
echo "<hr>";

try {
    // Connect to MASTER database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>Database: " . MASTER_DB_NAME . "</h3>";
    
    // Check if table exists
    $tables = $masterDb->query("SHOW TABLES LIKE 'cash_accounts'")->fetchAll();
    
    if (empty($tables)) {
        echo "<div style='background: #fee; padding: 1rem; border-left: 4px solid #f00; margin: 1rem 0;'>";
        echo "❌ <strong>Table 'cash_accounts' NOT FOUND in master database!</strong><br>";
        echo "You need to run setup first.<br>";
        echo "<a href='fix-create-tables-now.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 6px;'>🚀 Run Setup Now</a>";
        echo "</div>";
    } else {
        echo "✅ Table 'cash_accounts' exists<br><br>";
        
        // Get all accounts
        $accounts = $masterDb->query("SELECT * FROM cash_accounts ORDER BY business_id, id")->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($accounts)) {
            echo "<div style='background: #fef3c7; padding: 1rem; border-left: 4px solid #f59e0b; margin: 1rem 0;'>";
            echo "⚠️  <strong>No accounts found in cash_accounts table!</strong><br>";
            echo "Table exists but empty. You need to create default accounts.<br>";
            echo "<a href='fix-create-tables-now.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 6px;'>🚀 Create Default Accounts</a>";
            echo "</div>";
        } else {
            echo "✅ Found " . count($accounts) . " account(s)<br><br>";
            
            // Display accounts
            echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
            echo "<thead style='background: #3b82f6; color: white;'>";
            echo "<tr>";
            echo "<th>ID</th>";
            echo "<th>Business ID</th>";
            echo "<th>Account Name</th>";
            echo "<th>Type</th>";
            echo "<th>Balance</th>";
            echo "<th>Default</th>";
            echo "<th>Active</th>";
            echo "</tr>";
            echo "</thead>";
            echo "<tbody>";
            
            foreach ($accounts as $acc) {
                $rowColor = '';
                if ($acc['account_type'] == 'owner_capital') {
                    $rowColor = 'background: #fef3c7;';
                }
                
                echo "<tr style='$rowColor'>";
                echo "<td>{$acc['id']}</td>";
                echo "<td>{$acc['business_id']}</td>";
                echo "<td><strong>{$acc['account_name']}</strong></td>";
                echo "<td>{$acc['account_type']}</td>";
                echo "<td>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</td>";
                echo "<td>" . ($acc['is_default_account'] ? '⭐ Yes' : 'No') . "</td>";
                echo "<td>" . ($acc['is_active'] ? '✅ Yes' : '❌ No') . "</td>";
                echo "</tr>";
            }
            
            echo "</tbody>";
            echo "</table>";
            
            // Check specific business
            echo "<hr>";
            echo "<h3>Current Business: " . ACTIVE_BUSINESS_ID . "</h3>";
            
            $businessId = getMasterBusinessId();
            
            echo "Business ID: <strong>{$businessId}</strong><br><br>";
            
            $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital' AND is_active = 1");
            $stmt->execute([$businessId]);
            $ownerAccount = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ownerAccount) {
                echo "<div style='background: #d1fae5; padding: 1rem; border-left: 4px solid #10b981; margin: 1rem 0;'>";
                echo "✅ <strong>Kas Modal Owner found!</strong><br>";
                echo "Account ID: {$ownerAccount['id']}<br>";
                echo "Account Name: {$ownerAccount['account_name']}<br>";
                echo "Balance: Rp " . number_format($ownerAccount['current_balance'], 0, ',', '.') . "<br>";
                echo "</div>";
                
                echo "<a href='modules/owner/owner-capital-monitor.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px;'>📊 Open Monitor</a>";
            } else {
                echo "<div style='background: #fee; padding: 1rem; border-left: 4px solid #f00; margin: 1rem 0;'>";
                echo "❌ <strong>Kas Modal Owner NOT FOUND for business ID {$businessId}!</strong><br>";
                echo "Available accounts are for different businesses.<br>";
                echo "<a href='fix-create-tables-now.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 6px;'>🚀 Create Accounts for This Business</a>";
                echo "</div>";
            }
        }
    }
    
    echo "<hr>";
    echo "<a href='index.php' style='display: inline-block; padding: 0.5rem 1rem; background: #6b7280; color: white; text-decoration: none; border-radius: 6px;'>← Back to Dashboard</a>";
    
} catch (Exception $e) {
    echo "<div style='background: #fee; padding: 1rem; border-left: 4px solid #f00; margin: 1rem 0;'>";
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "</div>";
}
?>
