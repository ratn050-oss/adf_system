<?php
/**
 * FINAL FIX: Complete CQC Setup
 * Ensures menus are assigned and permissions are correct
 */

header('Content-Type: text/html; charset=utf-8');

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$masterDb = $isHosting ? 'adfb2574_adf' : 'adf_system';

echo "<h2>🔧 FINAL FIX: Complete CQC Menu Setup</h2>\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // === STEP 1: Verify CQC business ===
    echo "<h3>Step 1️⃣: Verifying CQC business...</h3>\n";
    $cqc = $pdo->query("SELECT id, business_code, business_name FROM businesses WHERE id = 7 OR business_code = 'CQC'")->fetch(PDO::FETCH_ASSOC);
    
    if (!$cqc) {
        echo "❌ CQC business not found! Aborting.\n";
        exit;
    }
    
    echo "✅ CQC Business: ID=" . $cqc['id'] . ", Code=" . $cqc['business_code'] . ", Name=" . htmlspecialchars($cqc['business_name']) . "\n\n";
    $businessId = $cqc['id'];
    
    // === STEP 2: Get all menus ===
    echo "<h3>Step 2️⃣: Getting all menus...</h3>\n";
    $menus = $pdo->query("SELECT id, menu_code, menu_name FROM menu_items WHERE is_active = 1 ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Found " . count($menus) . " active menus\n\n";
    
    // === STEP 3: Ensure all menus assigned to CQC ===
    echo "<h3>Step 3️⃣: Ensuring menus assigned to CQC business...</h3>\n";
    
    // Clear old
    $pdo->exec("DELETE FROM business_menu_config WHERE business_id = $businessId");
    echo "   Cleared old assignments\n";
    
    // Insert all
    $stmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
    
    foreach ($menus as $menu) {
        $stmt->execute([$businessId, $menu['id']]);
    }
    
    echo "✅ Assigned " . count($menus) . " menus to CQC\n\n";
    
    // === STEP 4: Get users ===
    echo "<h3>Step 4️⃣: Setting up permissions for users...</h3>\n";
    $users = $pdo->query("SELECT id, username FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   Found " . count($users) . " users:\n";
    foreach ($users as $u) {
        echo "   - " . htmlspecialchars($u['username']) . " (ID: " . $u['id'] . ")\n";
    }
    echo "\n";
    
    // === STEP 5: Ensure all users have permissions ===
    echo "<h3>Step 5️⃣: Granting permissions...</h3>\n";
    
    // Clear old permissions for this business
    $pdo->exec("DELETE FROM user_menu_permissions WHERE business_id = $businessId");
    echo "   Cleared old permissions\n";
    
    // Insert new permissions
    $permStmt = $pdo->prepare("
        INSERT INTO user_menu_permissions 
        (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) 
        VALUES (?, ?, ?, 1, 1, 1, 1)
    ");
    
    $permCount = 0;
    foreach ($users as $user) {
        foreach ($menus as $menu) {
            try {
                $permStmt->execute([$user['id'], $businessId, $menu['menu_code']]);
                $permCount++;
            } catch (Exception $e) {
                // Skip duplicates
            }
        }
    }
    
    echo "✅ Created " . $permCount . " permission records\n\n";
    
    // === STEP 6: Verify assignments ===
    echo "<h3>Step 6️⃣: Verifying setup...</h3>\n";
    
    $menuCount = $pdo->query("
        SELECT COUNT(*) as total FROM business_menu_config 
        WHERE business_id = $businessId AND is_enabled = 1
    ")->fetch(PDO::FETCH_ASSOC)['total'];
    
    $permCount = $pdo->query("
        SELECT COUNT(*) as total FROM user_menu_permissions 
        WHERE business_id = $businessId AND can_view = 1
    ")->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "   Menus assigned: " . $menuCount . "\n";
    echo "   Permissions set: " . $permCount . "\n\n";
    
    // === STEP 7: Show assigned menus ===
    echo "<h3>Step 7️⃣: Assigned menus for CQC:</h3>\n";
    $assigned = $pdo->query("
        SELECT m.menu_name, m.menu_code FROM menu_items m
        JOIN business_menu_config bmc ON bmc.menu_id = m.id
        WHERE bmc.business_id = $businessId
        ORDER BY m.menu_order
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ol>\n";
    foreach ($assigned as $menu) {
        echo "<li>" . htmlspecialchars($menu['menu_name']) . " (<code>" . $menu['menu_code'] . "</code>)</li>\n";
    }
    echo "</ol>\n";
    
    echo "\n<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid green; margin-top: 20px;'>\n";
    echo "<strong>✅ COMPLETE!</strong><br>\n";
    echo "CQC Business Setup:<br>\n";
    echo "✅ " . count($menus) . " menus assigned<br>\n";
    echo "✅ " . count($users) . " users configured<br>\n";
    echo "✅ " . $permCount . " permissions set<br>\n";
    echo "<br>\n";
    echo "<strong>Also fixed:</strong> Updated auth.php to include CQC mapping<br>\n";
    echo "<strong>Next:</strong> Logout and login again to CQC - menus should NOW appear! 🎉\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid red;'>\n";
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</div>\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; line-height: 1.6; }
h2, h3 { color: #333; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
</style>
