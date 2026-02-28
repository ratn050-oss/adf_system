<?php
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$systemDb = 'adf_system';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$systemDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== MENUS ASSIGNED TO CQC (business_id=7) & THEIR DETAILS ===\n";
    
    $result = $pdo->query("SELECT bmc.menu_id, bmc.is_enabled, mi.menu_code, mi.menu_name, mi.menu_url 
                           FROM business_menu_config bmc 
                           JOIN menu_items mi ON bmc.menu_id = mi.id 
                           WHERE bmc.business_id = 7 
                           ORDER BY bmc.menu_id");
    $menus = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total: " . count($menus) . " menus\n\n";
    foreach ($menus as $row) {
        $status = $row['is_enabled'] ? '✅' : '❌';
        echo "$status ID" . $row['menu_id'] . " | Code: " . $row['menu_code'] . " | Name: " . $row['menu_name'] . " | URL: " . $row['menu_url'] . "\n";
    }
    
    echo "\n=== SEARCHING FOR CQC PROJECTS MENU ===\n";
    $result = $pdo->query("SELECT * FROM menu_items WHERE menu_code LIKE '%cqc%' OR menu_name LIKE '%cqc%' OR menu_url LIKE '%cqc%'");
    $cqcMenus = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cqcMenus)) {
        echo "❌ No CQC-related menus found in menu_items\n";
        echo "Need to create menu for CQC Projects\n";
    } else {
        echo "Found " . count($cqcMenus) . " CQC-related menus:\n";
        foreach ($cqcMenus as $menu) {
            echo "  ID:" . $menu['id'] . " - " . $menu['menu_name'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
