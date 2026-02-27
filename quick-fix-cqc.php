<?php
/**
 * Quick Fix for CQC Business Menus
 * Run this to immediately fix the CQC menus
 */

header('Content-Type: text/html; charset=utf-8');

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$masterDb = $isHosting ? 'adfb2574_adf' : 'adf_system';

echo "<style>\nbody { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; line-height: 1.6; }\nh2, h3 { color: #333; }\ncode { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }\n.success { background: #e8f5e9; padding: 15px; border-left: 4px solid green; margin: 15px 0; }\n.error { background: #ffebee; padding: 15px; border-left: 4px solid red; margin: 15px 0; }\n</style>\n";

echo "<h2>⚡ Quick Fix: CQC Menus</h2>\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // CQC business ID is 7
    $businessId = 7;
    $businessName = 'CQC';
    
    echo "<p><strong>Target:</strong> Business ID 7 (CQC)</p>\n";
    echo "<hr>\n";
    
    // Step 1: Get all active menus
    echo "<h3>Step 1: Fetching menus...</h3>\n";
    $menus = $pdo->query("SELECT id, menu_name FROM menu_items WHERE is_active = 1 ORDER BY menu_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Found " . count($menus) . " menus\n";
    
    // Step 2: Clear and reassign
    echo "<h3>Step 2: Clearing old assignments...</h3>\n";
    $pdo->exec("DELETE FROM business_menu_config WHERE business_id = $businessId");
    echo "✅ Cleared\n";
    
    echo "<h3>Step 3: Assigning menus...</h3>\n";
    $stmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_item_id) VALUES (?, ?)");
    $count = 0;
    foreach ($menus as $m) {
        $stmt->execute([$businessId, $m['id']]);
        $count++;
    }
    echo "✅ Assigned $count menus\n";
    
    // Step 4: Verify
    echo "<h3>Step 4: Verifying...</h3>\n";
    $verified = $pdo->query("SELECT COUNT(*) as total FROM business_menu_config WHERE business_id = $businessId")->fetch(PDO::FETCH_ASSOC);
    echo "✅ Verified: " . $verified['total'] . " menus now assigned\n";
    
    echo "\n<div class='success'>\n";
    echo "<strong>✅ CQC Menus Fixed!</strong><br>\n";
    echo "All " . count($menus) . " menus are now assigned to CQC business.<br>\n";
    echo "<strong>Try:</strong> Refresh the CQC dashboard - menus should appear now! 🎉\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<div class='error'>\n";
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</div>\n";
}
?>
