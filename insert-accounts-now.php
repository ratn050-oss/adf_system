<?php
/**
 * INSERT DEFAULT ACCOUNTS NOW
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<h2>💰 INSERT DEFAULT ACCOUNTS</h2>";
echo "<hr>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>Current Configuration:</h3>";
    echo "ACTIVE_BUSINESS_ID: <strong>" . ACTIVE_BUSINESS_ID . "</strong><br>";
    echo "DB_NAME: <strong>" . DB_NAME . "</strong><br>";
    echo "<hr>";
    
    // Get all businesses
    $businesses = $masterDb->query("SELECT id, business_name FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Businesses Found:</h3>";
    foreach ($businesses as $biz) {
        echo "- ID: {$biz['id']} - {$biz['business_name']}" . ($biz['id'] == ACTIVE_BUSINESS_ID ? ' <strong style="color: green;">← ACTIVE</strong>' : '') . "<br>";
    }
    echo "<hr>";
    
    echo "<h3>Creating Accounts:</h3>";
    
    foreach ($businesses as $biz) {
        $bizId = $biz['id'];
        
        // Check existing
        $stmt = $masterDb->prepare("SELECT COUNT(*) as cnt FROM cash_accounts WHERE business_id = ?");
        $stmt->execute([$bizId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing['cnt'] > 0) {
            echo "ℹ️  <strong>{$biz['business_name']}</strong>: Already has {$existing['cnt']} accounts (skipping)<br>";
            continue;
        }
        
        // Insert 3 accounts
        $accounts = [
            [
                'name' => 'Kas Operasional',
                'type' => 'cash',
                'is_default' => 1,
                'desc' => 'Kas untuk pendapatan operasional'
            ],
            [
                'name' => 'Kas Modal Owner',
                'type' => 'owner_capital',
                'is_default' => 1,
                'desc' => 'Dana dari pemilik untuk kebutuhan operasional harian'
            ],
            [
                'name' => 'Bank',
                'type' => 'bank',
                'is_default' => 0,
                'desc' => 'Rekening bank utama bisnis'
            ]
        ];
        
        $stmt = $masterDb->prepare("
            INSERT INTO cash_accounts 
            (business_id, account_name, account_type, is_default_account, description, is_active) 
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        
        foreach ($accounts as $acc) {
            $stmt->execute([
                $bizId,
                $acc['name'],
                $acc['type'],
                $acc['is_default'],
                $acc['desc']
            ]);
        }
        
        echo "✅ <strong>{$biz['business_name']}</strong>: 3 accounts created<br>";
    }
    
    echo "<hr>";
    echo "<h3>Verification:</h3>";
    
    $allAccounts = $masterDb->query("
        SELECT ca.*, b.business_name 
        FROM cash_accounts ca 
        JOIN businesses b ON ca.business_id = b.id 
        ORDER BY b.id, ca.account_type
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total accounts: <strong>" . count($allAccounts) . "</strong><br><br>";
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f3f4f6;'>";
    echo "<th>ID</th><th>Business</th><th>Account Name</th><th>Type</th><th>Default</th><th>Active</th>";
    echo "</tr>";
    
    foreach ($allAccounts as $acc) {
        $isActive = ($acc['business_id'] == ACTIVE_BUSINESS_ID);
        $rowStyle = $isActive ? "background: #d1fae5; font-weight: bold;" : "";
        
        echo "<tr style='{$rowStyle}'>";
        echo "<td>{$acc['id']}</td>";
        echo "<td>{$acc['business_name']}</td>";
        echo "<td>{$acc['account_name']}</td>";
        echo "<td>{$acc['account_type']}</td>";
        echo "<td>" . ($acc['is_default_account'] ? '⭐' : '') . "</td>";
        echo "<td>" . ($acc['is_active'] ? '✓' : '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<h3>✅ DONE!</h3>";
    echo "<a href='debug-dropdown.php' style='padding: 1rem 2rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; display: inline-block; margin-right: 1rem;'>Test Dropdown Query</a>";
    echo "<a href='modules/cashbook/add.php' style='padding: 1rem 2rem; background: #10b981; color: white; text-decoration: none; border-radius: 8px; display: inline-block;'>Go to Cashbook Form</a>";
    
} catch (Exception $e) {
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
    echo $e->getMessage();
    echo "\n\nTrace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>
