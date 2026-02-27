<?php
/**
 * Fix CQC Menu Assignment
 * Check and assign menus to CQC business
 */

header('Content-Type: text/html; charset=utf-8');
echo "<h2>🔧 Fixing CQC Menu Assignment</h2>\n";

// Detect environment
$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

echo "<p><strong>Environment:</strong> " . ($isHosting ? "🌐 HOSTING" : "💻 LOCAL") . "</p>\n";
echo "<hr>\n";

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$masterDb = $isHosting ? 'adfb2574_adf' : 'adf_system';

try {
    // Connect to master database
    echo "<h3>1️⃣ Checking master database menus...</h3>\n";
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all menus
    $menus = $pdo->query("SELECT * FROM menu_items ORDER BY `order`")->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Found " . count($menus) . " menus\n";
    
    foreach ($menus as $menu) {
        echo "   - " . $menu['id'] . ": " . $menu['name'] . " (" . $menu['code'] . ")\n";
    }
    
    echo "\n<h3>2️⃣ Checking CQC business menu assignments...</h3>\n";
    
    // Find CQC business
    $cqc = $pdo->query("SELECT * FROM businesses WHERE name = 'CQC' OR code = 'cqc'")->fetch(PDO::FETCH_ASSOC);
    
    if (!$cqc) {
        echo "❌ CQC business not found in master database\n";
        echo "<p>Creating CQC business in master database...</p>\n";
        
        $pdo->exec("
            INSERT INTO businesses (name, code, database_name, type, is_active) 
            VALUES ('CQC', 'cqc', 'adfb2574_cqc', 'manufacturing', 1)
        ");
        
        $cqc = $pdo->query("SELECT * FROM businesses WHERE name = 'CQC'")->fetch(PDO::FETCH_ASSOC);
        echo "✅ Created CQC business with ID: " . $cqc['id'] . "\n";
    } else {
        echo "✅ Found CQC business - ID: " . $cqc['id'] . "\n";
    }
    
    $cqcId = $cqc['id'];
    
    echo "\n<h3>3️⃣ Assigning all menus to CQC (ID: $cqcId)...</h3>\n";
    
    // First, remove any existing assignments
    $pdo->exec("DELETE FROM business_menu_config WHERE business_id = $cqcId");
    echo "✅ Cleared old menu assignments\n";
    
    // Assign all menus
    $count = 0;
    foreach ($menus as $menu) {
        try {
            $pdo->exec("
                INSERT INTO business_menu_config (business_id, menu_item_id) 
                VALUES ($cqcId, " . $menu['id'] . ")
            ");
            $count++;
        } catch (Exception $e) {
            echo "   ⚠️ Could not assign menu " . $menu['name'] . ": " . $e->getMessage() . "\n";
        }
    }
    
    echo "✅ Assigned $count menus to CQC\n";
    
    echo "\n<h3>4️⃣ Verifying assignments...</h3>\n";
    $assigned = $pdo->query("
        SELECT m.id, m.name, m.code FROM menu_items m
        JOIN business_menu_config bmc ON bmc.menu_item_id = m.id
        WHERE bmc.business_id = $cqcId
        ORDER BY m.order
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ CQC now has " . count($assigned) . " menus:\n";
    foreach ($assigned as $menu) {
        echo "   - " . $menu['name'] . " (" . $menu['code'] . ")\n";
    }
    
    echo "\n<h3>✅ Complete!</h3>\n";
    echo "<p style='background: #e8f5e9; padding: 15px; border-left: 4px solid green;'>\n";
    echo "CQC business is now assigned all menus.<br>\n";
    echo "Try refreshing the CQC dashboard - menus should now appear! 🎉\n";
    echo "</p>\n";
    
} catch (Exception $e) {
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; max-width: 800px; margin: 0 auto; }
h2, h3 { color: #333; }
code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
</style>
