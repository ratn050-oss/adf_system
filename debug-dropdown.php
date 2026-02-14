<?php
/**
 * DEBUG - Why Dropdown Empty?
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<h2>🔍 DEBUG DROPDOWN ISSUE</h2>";
echo "<hr>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>1. Configuration Check</h3>";
    echo "ACTIVE_BUSINESS_ID = <strong>" . ACTIVE_BUSINESS_ID . "</strong><br>";
    echo "DB_NAME (Master) = <strong>" . DB_NAME . "</strong><br>";
    echo "<hr>";
    
    echo "<h3>2. All Accounts in Database</h3>";
    $allAccounts = $masterDb->query("SELECT * FROM cash_accounts ORDER BY business_id, id")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($allAccounts)) {
        echo "❌ NO ACCOUNTS FOUND IN DATABASE!<br>";
        echo "Run fix-create-tables-now.php first!<br>";
    } else {
        echo "✅ Found " . count($allAccounts) . " accounts:<br>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin-top: 10px;'>";
        echo "<tr><th>ID</th><th>Business ID</th><th>Account Name</th><th>Type</th><th>Is Default</th><th>Is Active</th></tr>";
        foreach ($allAccounts as $acc) {
            echo "<tr>";
            echo "<td>{$acc['id']}</td>";
            echo "<td>{$acc['business_id']}</td>";
            echo "<td>{$acc['account_name']}</td>";
            echo "<td>{$acc['account_type']}</td>";
            echo "<td>" . ($acc['is_default_account'] ? '✓' : '') . "</td>";
            echo "<td>" . ($acc['is_active'] ? '✓' : '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "<hr>";
    
    echo "<h3>3. Query Test (Same as cashbook/add.php)</h3>";
    $businessId = ACTIVE_BUSINESS_ID;
    
    $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? ORDER BY is_default_account DESC, account_name");
    $stmt->execute([$businessId]);
    $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query: <code>SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = {$businessId}</code><br><br>";
    
    if (empty($cashAccounts)) {
        echo "❌ QUERY RETURNED EMPTY!<br>";
        echo "<strong style='color: red;'>Problem: No accounts found for business_id = {$businessId}</strong><br>";
        echo "<br>Possible solutions:<br>";
        echo "1. Check if ACTIVE_BUSINESS_ID in config matches business_id in database<br>";
        echo "2. Run fix-create-tables-now.php to create accounts<br>";
    } else {
        echo "✅ Query returned " . count($cashAccounts) . " accounts:<br>";
        echo "<ul>";
        foreach ($cashAccounts as $acc) {
            echo "<li><strong>{$acc['account_name']}</strong> ({$acc['account_type']}) - ID: {$acc['id']}</li>";
        }
        echo "</ul>";
        
        echo "<h4>Dropdown HTML Preview:</h4>";
        echo "<select name='cash_account_id' style='padding: 8px; font-size: 14px; width: 300px;'>";
        echo "<option value=''>-- Pilih Akun Kas (opsional) --</option>";
        foreach ($cashAccounts as $acc) {
            echo "<option value='{$acc['id']}'>{$acc['account_name']}</option>";
        }
        echo "</select>";
    }
    echo "<hr>";
    
    echo "<h3>4. Check Businesses Table</h3>";
    $businesses = $masterDb->query("SELECT id, business_name FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
    echo "Businesses in database:<br>";
    echo "<ul>";
    foreach ($businesses as $biz) {
        echo "<li>ID: {$biz['id']} - {$biz['business_name']}" . ($biz['id'] == ACTIVE_BUSINESS_ID ? ' <strong style="color: green;">← ACTIVE</strong>' : '') . "</li>";
    }
    echo "</ul>";
    
    echo "<hr>";
    echo "<a href='modules/cashbook/add.php' style='padding: 1rem 2rem; background: #10b981; color: white; text-decoration: none; border-radius: 8px; display: inline-block;'>Test Cashbook Form</a>";
    
} catch (Exception $e) {
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
    echo $e->getMessage();
    echo "</pre>";
}
?>
