<?php
/**
 * Setup CQC Projects menu on any environment (local or hosting)
 * Run once: /setup-cqc-menu.php
 */

// Auto-detect environment
$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);

if ($isHosting) {
    $dbHost = 'localhost';
    $dbUser = 'adfb2574_adfsystem';
    $dbPass = '@Nnoc2025';
    $systemDb = 'adfb2574_adf';
} else {
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = '';
    $systemDb = 'adf_system';
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$systemDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<pre>\n";
    echo "=== SETUP CQC PROJECTS MENU ===\n";
    echo "Environment: " . ($isHosting ? 'HOSTING' : 'LOCAL') . "\n";
    echo "Database: $systemDb\n\n";
    
    // Check if menu already exists
    $check = $pdo->query("SELECT id FROM menu_items WHERE menu_code = 'cqc-projects'");
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        echo "✅ Menu already exists with ID: " . $existing['id'] . "\n";
        $menuId = $existing['id'];
    } else {
        // Create menu - tanpa updated_at
        $pdo->exec("INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active, created_at) 
                    VALUES ('cqc-projects', 'CQC Projects', 'icon-project', 'modules/cqc-projects/', 9, 1, NOW())");
        
        $menuId = $pdo->lastInsertId();
        echo "✅ Created menu with ID: $menuId\n";
    }
    
    echo "\n=== STEP 2: Assign to CQC business ===\n";
    
    // Get CQC business ID
    $bizStmt = $pdo->query("SELECT id FROM businesses WHERE business_code = 'CQC' LIMIT 1");
    $biz = $bizStmt->fetch(PDO::FETCH_ASSOC);
    $bizId = $biz ? $biz['id'] : 7;
    echo "CQC Business ID: $bizId\n";
    
    // Check if already assigned to CQC
    $check = $pdo->prepare("SELECT id FROM business_menu_config WHERE business_id = ? AND menu_id = ?");
    $check->execute([$bizId, $menuId]);
    $assigned = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($assigned) {
        echo "✅ Menu already assigned to CQC business\n";
    } else {
        $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled, created_at) VALUES (?, ?, 1, NOW())")
            ->execute([$bizId, $menuId]);
        echo "✅ Assigned menu to CQC business\n";
    }
    
    // STEP 3: Add permissions for all CQC users
    echo "\n=== STEP 3: Add user permissions ===\n";
    
    $userStmt = $pdo->prepare("SELECT uba.user_id, u.username FROM user_business_assignment uba JOIN users u ON u.id = uba.user_id WHERE uba.business_id = ?");
    $userStmt->execute([$bizId]);
    $cqcUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cqcUsers as $user) {
        $permCheck = $pdo->prepare("SELECT user_id FROM user_menu_permissions WHERE user_id = ? AND business_id = ? AND menu_code = 'cqc-projects'");
        $permCheck->execute([$user['user_id'], $bizId]);
        
        if ($permCheck->fetch()) {
            echo "✅ User {$user['username']} (ID:{$user['user_id']}) already has cqc-projects permission\n";
        } else {
            $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, 'cqc-projects', 1, 1, 1, 1)")
                ->execute([$user['user_id'], $bizId, $menuId]);
            echo "✅ Added cqc-projects permission for {$user['username']} (ID:{$user['user_id']})\n";
        }
    }
    
    // STEP 4: Fix any NULL menu_id entries
    echo "\n=== STEP 4: Fix NULL menu_id entries ===\n";
    $fixed = $pdo->exec("UPDATE user_menu_permissions ump JOIN menu_items mi ON mi.menu_code = ump.menu_code SET ump.menu_id = mi.id WHERE ump.menu_id IS NULL");
    echo "✅ Fixed $fixed entries with NULL menu_id\n";
    
    echo "\n=== ALL DONE ===\n";
    echo "CQC Projects menu is now set up!\n";
    echo "👉 Clear browser cache (Ctrl+F5) and reload\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
