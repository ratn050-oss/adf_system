<?php
// Update menus to match real system
define('APP_ACCESS', true);
require_once 'config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Starting menu update...\n\n";
    
    // Delete existing relationships
    $pdo->exec("DELETE FROM business_menu_config");
    $pdo->exec("DELETE FROM user_menu_permissions");
    $pdo->exec("DELETE FROM menu_items");
    echo "✓ Cleared old menu data\n";
    
    // Insert new menus
    $menus = [
        [1, 'dashboard', 'Dashboard', 'speedometer2', 'index.php', 1],
        [2, 'cashbook', 'Buku Kas Besar', 'journal-text', 'modules/cashbook/', 2],
        [3, 'divisions', 'Kelola Divisi', 'building', 'modules/divisions/', 3],
        [4, 'frontdesk', 'Frontdesk', 'door-open', 'modules/frontdesk/', 4],
        [5, 'sales_invoice', 'Sales Invoice', 'file-text', 'modules/sales/', 5],
        [6, 'procurement', 'PO & SHOOP', 'shopping-cart', 'modules/procurement/', 6],
        [7, 'reports', 'Reports', 'graph-up', 'modules/reports/', 7],
        [8, 'investor', 'Investor', 'currency-dollar', 'modules/investor/', 8],
        [9, 'project', 'Project', 'briefcase', 'modules/project/', 9],
        [10, 'finance', 'Manajemen Keuangan', 'wallet2', 'modules/finance/', 10],
        [11, 'settings', 'Pengaturan', 'gear', 'modules/settings/', 11]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO menu_items (id, menu_code, menu_name, menu_icon, menu_url, menu_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    foreach ($menus as $menu) {
        $stmt->execute($menu);
        echo "✓ Added: {$menu[2]}\n";
    }
    
    echo "\n✓ 10 menus inserted successfully!\n\n";
    
    // Re-assign all menus to both businesses
    echo "Assigning menus to businesses...\n";
    $businesses = $pdo->query("SELECT id, business_name FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
    $assignStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
    
    foreach ($businesses as $biz) {
        foreach ($menus as $menu) {
            $assignStmt->execute([$biz['id'], $menu[0]]);
        }
        echo "✓ Assigned all menus to {$biz['business_name']}\n";
    }
    
    echo "\n✓ Menu assignment complete!\n\n";
    
    // Re-assign permissions for busita (user_id = 2) - owner
    echo "Restoring permissions for owner...\n";
    $permStmt = $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_id, can_view, can_create, can_update, can_delete) VALUES (2, ?, ?, 1, 1, 1, 1)");
    
    foreach ($businesses as $biz) {
        foreach ($menus as $menu) {
            $permStmt->execute([$biz['id'], $menu[0]]);
        }
        echo "✓ Full permissions granted for {$biz['business_name']}\n";
    }
    
    echo "\n✅ All done! Menu system updated successfully!\n\n";
    
    // Show final result
    echo "Final Menu List:\n";
    echo str_repeat("=", 60) . "\n";
    $result = $pdo->query("SELECT id, menu_code, menu_name, menu_icon FROM menu_items ORDER BY menu_order");
    foreach ($result as $row) {
        echo sprintf("ID: %2d | %-20s | %s\n", $row['id'], $row['menu_name'], $row['menu_code']);
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
