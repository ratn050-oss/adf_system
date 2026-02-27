<?php
/**
 * Debug CQC Menu Display Issue
 */

header('Content-Type: text/html; charset=utf-8');
echo "<h2>🔍 Debugging CQC Menu Issue</h2>\n";

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
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Debug 1: Check if businesses table exists and what's in it
    echo "<h3>1️⃣ Checking BUSINESSES table...</h3>\n";
    try {
        $businesses = $pdo->query("SELECT * FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Businesses found: " . count($businesses) . "\n";
        echo "<table style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
        echo "<tr style='background: #f0f0f0; border: 1px solid #ddd;'><th style='padding: 8px; border: 1px solid #ddd;'>ID</th><th style='padding: 8px; border: 1px solid #ddd;'>Name</th><th style='padding: 8px; border: 1px solid #ddd;'>Code</th></tr>\n";
        foreach ($businesses as $b) {
            $highlight = (isset($b['code']) && $b['code'] == 'cqc') ? "style='background: #fff3e0;'" : "";
            echo "<tr $highlight style='border: 1px solid #ddd;'><td style='padding: 8px; border: 1px solid #ddd;'>" . $b['id'] . "</td><td style='padding: 8px; border: 1px solid #ddd;'>" . $b['name'] . "</td><td style='padding: 8px; border: 1px solid #ddd;'>" . ($b['code'] ?? 'N/A') . "</td></tr>\n";
        }
        echo "</table>\n";
    } catch (Exception $e) {
        echo "❌ BUSINESSES table error: " . $e->getMessage() . "\n";
    }
    
    // Debug 2: Check menu_items
    echo "\n<h3>2️⃣ Checking MENU_ITEMS table...</h3>\n";
    try {
        $menus = $pdo->query("SELECT * FROM menu_items ORDER BY `order`")->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Menus found: " . count($menus) . "\n";
        echo "<ol style='margin-left: 20px;'>\n";
        foreach ($menus as $m) {
            echo "<li>" . $m['name'] . " (ID: " . $m['id'] . ", Code: " . $m['code'] . ")</li>\n";
        }
        echo "</ol>\n";
    } catch (Exception $e) {
        echo "❌ MENU_ITEMS table error: " . $e->getMessage() . "\n";
    }
    
    // Debug 3: Check business_menu_config
    echo "\n<h3>3️⃣ Checking BUSINESS_MENU_CONFIG table...</h3>\n";
    try {
        $configs = $pdo->query("SELECT * FROM business_menu_config")->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Menu assignments found: " . count($configs) . "\n";
        
        if (!empty($configs)) {
            echo "<table style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
            echo "<tr style='background: #f0f0f0; border: 1px solid #ddd;'><th style='padding: 8px; border: 1px solid #ddd;'>Business ID</th><th style='padding: 8px; border: 1px solid #ddd;'>Menu ID</th></tr>\n";
            
            $cqcCount = 0;
            foreach ($configs as $c) {
                $highlight = (isset($c['business_id']) && $c['business_id'] == 7) ? "style='background: #e8f5e9;'" : "";
                if (isset($c['business_id']) && $c['business_id'] == 7) $cqcCount++;
                echo "<tr $highlight style='border: 1px solid #ddd;'><td style='padding: 8px; border: 1px solid #ddd;'>" . $c['business_id'] . "</td><td style='padding: 8px; border: 1px solid #ddd;'>" . $c['menu_item_id'] . "</td></tr>\n";
            }
            echo "</table>\n";
            
            echo "\n<strong>CQC (ID: 7) has " . $cqcCount . " menu assignments</strong>\n";
        } else {
            echo "❌ NO menu assignments found!\n";
        }
    } catch (Exception $e) {
        echo "❌ BUSINESS_MENU_CONFIG table error: " . $e->getMessage() . "\n";
    }
    
    // Debug 4: Check index.php code
    echo "\n<h3>4️⃣ Checking how menus are loaded in index.php...</h3>\n";
    $indexFile = $isHosting ? '/home/adfb2574/public_html/index.php' : __DIR__ . '/../index.php';
    
    if (file_exists($indexFile)) {
        echo "✅ Found index.php\n";
        $content = file_get_contents($indexFile);
        
        if (strpos($content, 'ACTIVE_BUSINESS_ID') !== false) {
            echo "✅ Code checks ACTIVE_BUSINESS_ID\n";
        } else {
            echo "⚠️ Code doesn't check ACTIVE_BUSINESS_ID\n";
        }
        
        if (strpos($content, 'business_menu_config') !== false) {
            echo "✅ Code queries business_menu_config\n";
        } else {
            echo "⚠️ Code doesn't query business_menu_config\n";
        }
    } else {
        echo "❌ index.php not found at: $indexFile\n";
    }
    
    echo "\n<h3>✅ Diagnostic Complete</h3>\n";
    echo "<p style='background: #fff3e0; padding: 15px; border-left: 4px solid orange;'>\n";
    echo "Based on this diagnostic, we can identify the exact issue.<br>\n";
    echo "Share the output above and I'll fix it!\n";
    echo "</p>\n";
    
} catch (Exception $e) {
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; max-width: 1000px; margin: 0 auto; }
h2, h3 { color: #333; }
code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
table { margin: 10px 0; }
</style>
