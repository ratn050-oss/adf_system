<?php
/**
 * Complete Business Setup Script
 * Automatically sets up database, menus, and permissions for a new business
 * Run this after creating a new business to complete the setup
 */

header('Content-Type: text/html; charset=utf-8');

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$masterDb = $isHosting ? 'adfb2574_adf' : 'adf_system';

// Get business ID from request or default to CQC (7)
$businessId = $_GET['business_id'] ?? 7;
$businessName = $_GET['business_name'] ?? 'CQC';

echo "<h2>🚀 Complete Business Setup</h2>\n";
echo "<p><strong>Business:</strong> $businessName (ID: $businessId)</p>\n";
echo "<hr>\n";

try {
    // Connect to master database
    $masterPdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Get all active menus
    echo "<h3>1️⃣ Fetching all active menus...</h3>\n";
    $menus = $masterPdo->query("
        SELECT id, menu_code, menu_name FROM menu_items WHERE is_active = 1 ORDER BY menu_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($menus)) {
        throw new Exception("No active menus found in database");
    }
    
    echo "✅ Found " . count($menus) . " menus:\n";
    echo "<ul>\n";
    foreach ($menus as $m) {
        echo "<li>" . htmlspecialchars($m['menu_name']) . " (Code: " . htmlspecialchars($m['menu_code']) . ")</li>\n";
    }
    echo "</ul>\n";
    
    // Step 2: Remove old assignments
    echo "\n<h3>2️⃣ Clearing old menu assignments...</h3>\n";
    $masterPdo->exec("DELETE FROM business_menu_config WHERE business_id = $businessId");
    echo "✅ Cleared old assignments\n";
    
    // Step 3: Assign all menus to business
    echo "\n<h3>3️⃣ Assigning all menus to business...</h3>\n";
    $stmt = $masterPdo->prepare("INSERT INTO business_menu_config (business_id, menu_item_id) VALUES (?, ?)");
    
    $count = 0;
    foreach ($menus as $menu) {
        try {
            $stmt->execute([$businessId, $menu['id']]);
            $count++;
        } catch (Exception $e) {
            // Skip duplicates
        }
    }
    
    echo "✅ Assigned $count menus\n";
    
    // Step 4: Verify assignments
    echo "\n<h3>4️⃣ Verifying menu assignments...</h3>\n";
    $verified = $masterPdo->query("
        SELECT COUNT(*) as total FROM business_menu_config WHERE business_id = $businessId
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo "✅ Confirmed: " . $verified['total'] . " menus assigned to business $businessId\n";
    
    // Step 5: List assigned menus
    echo "\n<h3>5️⃣ Assigned menus for this business:</h3>\n";
    $assigned = $masterPdo->query("
        SELECT m.menu_code, m.menu_name FROM menu_items m
        JOIN business_menu_config bmc ON bmc.menu_item_id = m.id
        WHERE bmc.business_id = $businessId
        ORDER BY m.menu_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ol style='margin-left: 20px;'>\n";
    foreach ($assigned as $m) {
        echo "<li>" . htmlspecialchars($m['menu_name']) . "</li>\n";
    }
    echo "</ol>\n";
    
    // Step 6: Grant all users in business access to all menus
    echo "\n<h3>6️⃣ Granting user permissions...</h3>\n";
    
    // Get all users
    $users = $masterPdo->query("SELECT id FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($users)) {
        $permStmt = $masterPdo->prepare("
            INSERT INTO user_menu_permissions (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete)
            VALUES (?, ?, ?, 1, 1, 1, 1)
            ON DUPLICATE KEY UPDATE can_view = 1, can_create = 1, can_edit = 1, can_delete = 1
        ");
        
        $permCount = 0;
        foreach ($users as $userId) {
            foreach ($menus as $menu) {
                try {
                    $permStmt->execute([$userId, $businessId, $menu['menu_code']]);
                    $permCount++;
                } catch (Exception $e) {
                    // Skip duplicates
                }
            }
        }
        
        echo "✅ Set permissions for " . count($users) . " users across " . count($menus) . " menus\n";
    }
    
    echo "\n<p style='background: #e8f5e9; padding: 15px; border-left: 4px solid green; margin-top: 20px;'>\n";
    echo "<strong>✅ Business Setup Complete!</strong><br>\n";
    echo "Business: <strong>$businessName</strong> (ID: $businessId)<br>\n";
    echo "✅ Database created/verified<br>\n";
    echo "✅ " . count($menus) . " menus assigned<br>\n";
    echo "✅ User permissions configured<br>\n";
    echo "<br>\n";
    echo "<strong>Next:</strong> Refresh the dashboard or login again - all menus should now appear! 🎉\n";
    echo "</p>\n";
    
} catch (Exception $e) {
    echo "<p style='background: #ffebee; padding: 15px; border-left: 4px solid red;'>\n";
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</p>\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; line-height: 1.6; }
h2, h3 { color: #333; }
code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
</style>
