<?php
/**
 * Quick diagnostic: check businesses + cash_accounts in master DB
 * Access: /debug-cash-dropdown.php
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/business_helper.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Cash Account Dropdown Debug</h2>";
echo "<p><strong>Environment:</strong> " . ($isProduction ?? 'unknown') . "</p>";
echo "<p><strong>DB_NAME (master):</strong> " . DB_NAME . "</p>";
echo "<p><strong>MASTER_DB_NAME:</strong> " . MASTER_DB_NAME . "</p>";
echo "<p><strong>ACTIVE_BUSINESS_ID:</strong> " . ACTIVE_BUSINESS_ID . "</p>";
echo "<p><strong>SESSION business_id:</strong> " . ($_SESSION['business_id'] ?? 'NOT SET') . "</p>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green'>✅ Master DB connected OK</p>";
    
    // Check businesses table
    echo "<h3>1. Businesses Table</h3>";
    try {
        $rows = $masterDb->query("SELECT id, business_name, business_code, database_name, is_active FROM businesses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "<p style='color:red'>❌ businesses table is EMPTY!</p>";
        } else {
            echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Name</th><th>Code</th><th>Database</th><th>Active</th></tr>";
            foreach ($rows as $r) {
                echo "<tr><td>{$r['id']}</td><td>{$r['business_name']}</td><td>{$r['business_code']}</td><td>{$r['database_name']}</td><td>{$r['is_active']}</td></tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ businesses table error: " . $e->getMessage() . "</p>";
    }
    
    // Check cash_accounts table
    echo "<h3>2. Cash Accounts Table</h3>";
    try {
        $rows = $masterDb->query("SELECT id, business_id, account_name, account_type, current_balance, is_active FROM cash_accounts ORDER BY business_id, id")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "<p style='color:red'>❌ cash_accounts table is EMPTY!</p>";
        } else {
            echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Biz ID</th><th>Name</th><th>Type</th><th>Balance</th><th>Active</th></tr>";
            foreach ($rows as $r) {
                echo "<tr><td>{$r['id']}</td><td>{$r['business_id']}</td><td>{$r['account_name']}</td><td>{$r['account_type']}</td><td>{$r['current_balance']}</td><td>{$r['is_active']}</td></tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ cash_accounts table error: " . $e->getMessage() . "</p>";
    }
    
    // Test getMasterBusinessId
    echo "<h3>3. getMasterBusinessId() Result</h3>";
    $bizId = getMasterBusinessId();
    echo "<p><strong>Result:</strong> " . var_export($bizId, true) . "</p>";
    
    // Test getNumericBusinessId for each known business
    echo "<h3>4. getNumericBusinessId() Tests</h3>";
    foreach (['narayana-hotel', 'bens-cafe', 'demo'] as $biz) {
        $id = getNumericBusinessId($biz);
        echo "<p>getNumericBusinessId('$biz') = " . var_export($id, true) . "</p>";
    }
    
    // Test the actual query from add.php
    echo "<h3>5. Actual Query from add.php</h3>";
    echo "<p>Using businessId = $bizId</p>";
    $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? AND account_type IN ('cash', 'bank', 'owner_capital') ORDER BY account_type = 'cash' DESC, account_type = 'bank' DESC, account_name");
    $stmt->execute([$bizId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Found " . count($accounts) . " accounts:</p>";
    if (!empty($accounts)) {
        echo "<ul>";
        foreach ($accounts as $a) {
            echo "<li>{$a['id']} - {$a['account_name']} ({$a['account_type']})</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color:red'>❌ No cash accounts found for business_id=$bizId !</p>";
        echo "<p><strong>FIX:</strong> Run <a href='fix-business-setup.php?biz=NARAYANAHOTEL&run'>fix-business-setup.php?biz=NARAYANAHOTEL&run</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Master DB connection FAILED: " . $e->getMessage() . "</p>";
}
