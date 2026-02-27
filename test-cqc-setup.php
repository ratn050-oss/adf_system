<?php
/**
 * Test CQC Setup via Command Line
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Determine environment
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$masterDb = 'adf_system';

echo "=== CQC Setup Test ===\n\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connected to master database\n\n";
    
    // Check CQC business
    $cqc = $pdo->query("
        SELECT id, business_code, business_name, database_name, is_active 
        FROM businesses 
        WHERE business_code = 'CQC' OR id = 7
    ")->fetch(PDO::FETCH_ASSOC);
    
    if (!$cqc) {
        echo "⚠️  CQC business not found locally. Adding it...\n";
        
        // Find a valid user for owner_id
        $owner = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $ownerId = $owner['id'] ?? 1;
        
        $pdo->exec("
            INSERT INTO businesses (id, business_code, business_name, database_name, owner_id, is_active)
            VALUES (7, 'CQC', 'CQC Enjiniring', 'adf_cqc', " . $ownerId . ", 1)
            ON DUPLICATE KEY UPDATE business_code='CQC', business_name='CQC Enjiniring', database_name='adf_cqc', is_active=1
        ");
        echo "✅ CQC business added/updated\n\n";
        
        $cqc = [
            'id' => 7,
            'business_code' => 'CQC',
            'business_name' => 'CQC Enjiniring',
            'database_name' => 'adf_cqc',
            'is_active' => 1
        ];
    } else {
        echo "✅ CQC Business Found:\n";
        echo "   ID: " . $cqc['id'] . "\n";
        echo "   Code: " . $cqc['business_code'] . "\n";
        echo "   Name: " . $cqc['business_name'] . "\n";
        echo "   DB: " . $cqc['database_name'] . "\n";
        echo "   Active: " . ($cqc['is_active'] ? 'Yes' : 'No') . "\n\n";
    }
    
    // Check menus assigned
    $menuCount = $pdo->query("
        SELECT COUNT(*) as cnt FROM business_menu_config 
        WHERE business_id = " . $cqc['id'] . " AND is_enabled = 1
    ")->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo "✅ Menus Assigned: " . $menuCount . "\n";
    
    if ($menuCount == 0) {
        echo "   ⚠️  No menus assigned! Running setup...\n";
        
        // Get all menus
        $menus = $pdo->query("
            SELECT id FROM menu_items WHERE is_active = 1
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        // Assign them
        $pdo->exec("DELETE FROM business_menu_config WHERE business_id = " . $cqc['id']);
        
        $stmt = $pdo->prepare("
            INSERT INTO business_menu_config (business_id, menu_id, is_enabled) 
            VALUES (?, ?, 1)
        ");
        
        foreach ($menus as $menuId) {
            $stmt->execute([$cqc['id'], $menuId]);
        }
        
        echo "   ✅ Assigned " . count($menus) . " menus\n\n";
    } else {
        echo "\n";
    }
    
    // Check permissions
    $permCount = $pdo->query("
        SELECT COUNT(*) as cnt FROM user_menu_permissions 
        WHERE business_id = " . $cqc['id']
    )->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo "✅ Permissions Set: " . $permCount . "\n";
    
    if ($permCount == 0) {
        echo "   ⚠️  No permissions! Running setup...\n";
        
        // Get users and menus
        $users = $pdo->query("SELECT id FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        $menus = $pdo->query("SELECT menu_code FROM menu_items WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        
        // Grant permissions
        $stmt = $pdo->prepare("
            INSERT INTO user_menu_permissions 
            (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) 
            VALUES (?, ?, ?, 1, 1, 1, 1)
        ");
        
        $count = 0;
        foreach ($users as $userId) {
            foreach ($menus as $menuCode) {
                try {
                    $stmt->execute([$userId, $cqc['id'], $menuCode]);
                    $count++;
                } catch (Exception $e) {
                }
            }
        }
        
        echo "   ✅ Created " . $count . " permission records\n\n";
    } else {
        echo "\n";
    }
    
    // Final check - show what's assigned
    echo "📋 Assigned Menus for CQC:\n";
    $assigned = $pdo->query("
        SELECT m.menu_name FROM menu_items m
        JOIN business_menu_config bmc ON bmc.menu_id = m.id
        WHERE bmc.business_id = " . $cqc['id'] . "
        ORDER BY m.menu_order
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($assigned as $menu) {
        echo "   • " . $menu . "\n";
    }
    
    echo "\n✅ SETUP COMPLETE!\n";
    echo "\n📌 Next Steps:\n";
    echo "   1. Go to https://adfsystem.online/\n";
    echo "   2. Logout if logged in\n"; 
    echo "   3. Login again to CQC\n";
    echo "   4. Check if menus appear in the sidebar\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
