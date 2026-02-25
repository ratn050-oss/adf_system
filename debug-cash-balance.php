<?php
// Debug cash accounts balance query
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';

echo "<h2>🔍 Debug Cash Accounts Balance</h2>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessId = getMasterBusinessId();
    
    echo "<p><strong>Business Identifier:</strong> {$businessIdentifier}</p>";
    echo "<p><strong>Mapped Business ID:</strong> {$businessId}</p>";
    
    echo "<hr>";
    echo "<h3>🏦 All Cash Accounts in Master DB:</h3>";
    
    $stmt = $masterDb->query("SELECT * FROM cash_accounts ORDER BY business_id, account_type");
    $allAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($allAccounts)) {
        echo "<p style='color: red;'>❌ No cash_accounts found in master DB!</p>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th><th>Business ID</th><th>Account Name</th><th>Account Type</th><th>Current Balance</th><th>Is Default</th>";
        echo "</tr>";
        
        foreach ($allAccounts as $acc) {
            $highlight = ($acc['business_id'] == $businessId) ? 'background: #d4edda;' : '';
            echo "<tr style='{$highlight}'>";
            echo "<td>{$acc['id']}</td>";
            echo "<td>{$acc['business_id']}</td>";
            echo "<td>{$acc['account_name']}</td>";
            echo "<td>{$acc['account_type']}</td>";
            echo "<td><strong>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</strong></td>";
            echo "<td>" . ($acc['is_default_account'] ? 'YES' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h3>💵 Petty Cash Query Test:</h3>";
    
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' AND is_default_account = 1");
    $stmt->execute([$businessId]);
    $pettyCashResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pettyCashResult) {
        echo "<p style='color: green;'>✅ Found Petty Cash Account!</p>";
        echo "<pre>" . print_r($pettyCashResult, true) . "</pre>";
        echo "<p><strong>Balance:</strong> Rp " . number_format($pettyCashResult['current_balance'], 0, ',', '.') . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Petty Cash NOT found for business_id = {$businessId}</p>";
        echo "<p><strong>Query:</strong></p>";
        echo "<pre>SELECT * FROM cash_accounts WHERE business_id = {$businessId} AND account_type = 'cash' AND is_default_account = 1</pre>";
    }
    
    echo "<hr>";
    echo "<h3>🔥 Modal Owner Query Test:</h3>";
    
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ownerCapitalResult) {
        echo "<p style='color: green;'>✅ Found Modal Owner Account!</p>";
        echo "<pre>" . print_r($ownerCapitalResult, true) . "</pre>";
        echo "<p><strong>Balance:</strong> Rp " . number_format($ownerCapitalResult['current_balance'], 0, ',', '.') . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Modal Owner NOT found for business_id = {$businessId}</p>";
        echo "<p><strong>Query:</strong></p>";
        echo "<pre>SELECT * FROM cash_accounts WHERE business_id = {$businessId} AND account_type = 'owner_capital'</pre>";
    }
    
    echo "<hr>";
    echo "<h3>🔧 Solution:</h3>";
    
    if (!$pettyCashResult && !$ownerCapitalResult) {
        echo "<p style='color: red; font-weight: bold;'>❌ Tidak ada cash accounts untuk business_id = {$businessId}</p>";
        echo "<p>Kemungkinan:</p>";
        echo "<ol>";
        echo "<li>Accounts ada tapi business_id tidak match</li>";
        echo "<li>Accounts belum dibuat untuk bisnis ini</li>";
        echo "<li>Query menggunakan wrong business_id mapping</li>";
        echo "</ol>";
        
        // Check if accounts exist with different business_id
        $stmt = $masterDb->query("SELECT DISTINCT business_id FROM cash_accounts");
        $existingBusinessIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p><strong>Business IDs yang ada di cash_accounts:</strong> " . implode(', ', $existingBusinessIds) . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<p><a href='modules/reports/daily.php'>← Back to Laporan Harian</a> | <a href='index.php'>Dashboard</a></p>";
?>
