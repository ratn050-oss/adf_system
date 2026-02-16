<?php
/**
 * Debug: Check Investor Schema and Data
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Investor Schema Check</h2>";

// Check investors table structure
echo "<h3>🔍 Investors Table Structure:</h3>";
try {
    $stmt = $db->query("DESCRIBE investors");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    foreach ($columns as $col) {
        echo "{$col['Field']} ({$col['Type']}) - {$col['Null']} - Key: {$col['Key']}\n";
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "<div style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Check investors data
echo "<h3>📊 Investors Data Count:</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM investors");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Total Investors: {$result['cnt']}</strong></p>";
    
    if ($result['cnt'] > 0) {
        echo "<p>Sample investors:</p>";
        $stmt = $db->query("SELECT * FROM investors LIMIT 5");
        $investors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>";
        print_r($investors);
        echo "</pre>";
    } else {
        echo "<div style='color:orange;'>⚠️ No investor records found</div>";
    }
} catch (Exception $e) {
    echo "<div style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Check investor_transactions table
echo "<h3>🔍 Investor Transactions Table:</h3>";
try {
    $stmt = $db->query("DESCRIBE investor_transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>✅ Table exists. Columns:</p>";
    echo "<pre>";
    foreach ($columns as $col) {
        echo "{$col['Field']} ({$col['Type']})\n";
    }
    echo "</pre>";
    
    // Count records
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM investor_transactions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Total Transactions: {$result['cnt']}</strong></p>";
    
} catch (Exception $e) {
    echo "<div style='color:orange;'>⚠️ investor_transactions table not found or error: " . htmlspecialchars($e->getMessage()) . "</div>";
    
    // Check if investor_capital_transactions exists instead
    try {
        echo "<p>💡 Checking for investor_capital_transactions table...</p>";
        $stmt = $db->query("DESCRIBE investor_capital_transactions");
        echo "<p>✅ investor_capital_transactions exists!</p>";
    } catch (Exception $e2) {
        echo "<div style='color:orange;'>⚠️ investor_capital_transactions also not found</div>";
    }
}

// Check if BASE_URL is working
echo "<h3>🔗 BASE_URL Check:</h3>";
echo "<p>Current BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "</p>";

echo "<hr>";
echo "<p><a href='modules/investor/'>Back to Investor Module</a></p>";
?>
