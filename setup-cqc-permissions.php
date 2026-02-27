<?php
/**
 * Setup User Permissions for CQC Business
 * This grants all menu permissions to all users in CQC business
 */

header('Content-Type: text/html; charset=utf-8');

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$masterDb = $isHosting ? 'adfb2574_adf' : 'adf_system';

echo "<h2>🔐 Setup User Permissions for CQC</h2>\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = 7; // CQC
    
    // Step 1: Get all users
    echo "<h3>1️⃣ Finding users...</h3>\n";
    $users = $pdo->query("SELECT id, username FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($users) . " active users:\n";
    echo "<ul>\n";
    foreach ($users as $u) {
        echo "<li>" . htmlspecialchars($u['username']) . " (ID: " . $u['id'] . ")</li>\n";
    }
    echo "</ul>\n";
    
    // Step 2: Get all menus
    echo "\n<h3>2️⃣ Getting menu codes...</h3>\n";
    $menus = $pdo->query("
        SELECT DISTINCT menu_code FROM menu_items 
        WHERE is_active = 1 AND menu_code IS NOT NULL
        ORDER BY menu_order
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found " . count($menus) . " menu codes:\n";
    echo "<ul>\n";
    foreach ($menus as $code) {
        echo "<li><code>" . htmlspecialchars($code) . "</code></li>\n";
    }
    echo "</ul>\n";
    
    // Step 3: Grant permissions
    echo "\n<h3>3️⃣ Granting permissions...</h3>\n";
    
    // First clear any existing permissions for this business
    $pdo->exec("DELETE FROM user_menu_permissions WHERE business_id = $businessId");
    echo "✅ Cleared old permissions\n";
    
    // Insert new permissions
    $stmt = $pdo->prepare("
        INSERT INTO user_menu_permissions 
        (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) 
        VALUES (?, ?, ?, 1, 1, 1, 1)
    ");
    
    $totalPerms = 0;
    foreach ($users as $user) {
        foreach ($menus as $code) {
            try {
                $stmt->execute([$user['id'], $businessId, $code]);
                $totalPerms++;
            } catch (Exception $e) {
                // Skip duplicates
            }
        }
    }
    
    echo "✅ Created " . $totalPerms . " permission records\n";
    
    // Step 4: Verify permissions
    echo "\n<h3>4️⃣ Verifying permissions...</h3>\n";
    
    $permCount = $pdo->query("
        SELECT COUNT(*) as total FROM user_menu_permissions 
        WHERE business_id = $businessId AND can_view = 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo "Total active permissions for CQC: " . $permCount['total'] . "\n";
    
    // Show per user
    echo "<table style='border-collapse: collapse; width: 100%; margin-top: 10px;'>\n";
    echo "<tr style='background: #f0f0f0; border: 1px solid #ddd;'><th style='padding: 8px; text-align: left; border: 1px solid #ddd;'>User</th><th style='padding: 8px; text-align: left; border: 1px solid #ddd;'>Permissions</th></tr>\n";
    
    foreach ($users as $user) {
        $userPerms = $pdo->query(
            "SELECT COUNT(*) as total FROM user_menu_permissions WHERE user_id = ? AND business_id = ?",
            [$user['id'], $businessId]
        )->fetchColumn();
        
        echo "<tr style='border: 1px solid #ddd;'>\n";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($user['username']) . "</td>\n";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'><strong>" . $userPerms . "</strong></td>\n";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    echo "\n<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid green; margin-top: 20px;'>\n";
    echo "<strong>✅ Permissions Setup Complete!</strong><br>\n";
    echo "All " . count($users) . " users now have access to all " . count($menus) . " menus in CQC business.<br>\n";
    echo "<strong>Next:</strong> Logout CQC user and login again - all menus should appear! 🎉\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid red;'>\n";
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n";
    if (strpos($e->getMessage(), 'user_menu_permissions') !== false) {
        echo "<p>The <code>user_menu_permissions</code> table may not exist or may use different structure.</p>\n";
    }
    echo "</div>\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; line-height: 1.6; }
h2, h3 { color: #333; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
table { margin: 10px 0; }
</style>
