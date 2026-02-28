<?php
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$systemDb = 'adf_system';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$systemDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== CREATING CQC PROJECTS MENU ===\n\n";
    
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
    
    // Check if already assigned to CQC
    $check = $pdo->query("SELECT id FROM business_menu_config WHERE business_id = 7 AND menu_id = " . $menuId);
    $assigned = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($assigned) {
        echo "✅ Menu already assigned to CQC business\n";
    } else {
        // Assign to CQC - tanpa updated_at
        $pdo->exec("INSERT INTO business_menu_config (business_id, menu_id, is_enabled, created_at) 
                    VALUES (7, " . $menuId . ", 1, NOW())");
        echo "✅ Assigned menu to CQC business\n";
    }
    
    echo "\n=== SUCCESS ===\n";
    echo "CQC Projects menu is now visible in CQC sidebar!\n";
    echo "\n👉 Clear browser cache (Ctrl+F5) and reload\n";
    echo "👉 Login as CQC user and check sidebar\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
