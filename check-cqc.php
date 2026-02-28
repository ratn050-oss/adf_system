<?php
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$systemDb = 'adf_system';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$systemDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check businesses table structure
    echo "=== BUSINESSES TABLE STRUCTURE ===\n";
    $result = $pdo->query("DESCRIBE businesses");
    foreach ($result as $row) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    
    echo "\n=== CQC BUSINESS DATA ===\n";
    $result = $pdo->query("SELECT * FROM businesses WHERE business_code = 'cqc' LIMIT 1");
    $cqc = $result->fetch(PDO::FETCH_ASSOC);
    
    if ($cqc) {
        echo "✅ Found CQC business (ID=" . $cqc['id'] . "):\n";
        foreach ($cqc as $key => $value) {
            echo "  $key = $value\n";
        }
    } else {
        echo "❌ CQC business NOT found\n";
    }
    
    // Check what menu tables exist
    echo "\n=== MENU TABLES IN DATABASE ===\n";
    $tables = $pdo->query("SHOW TABLES LIKE '%menu%'");
    $menuTables = $tables->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($menuTables)) {
        echo "No menu tables found\n";
    } else {
        foreach ($menuTables as $table) {
            echo "- $table\n";
        }
    }
    
    echo "\n=== MENU STRUCTURE ===\n";
    
    // Check business_menu_config
    $tables = $pdo->query("SHOW TABLES LIKE 'business_menu_config'");
    if ($tables->rowCount() > 0) {
        echo "\n1. business_menu_config table EXISTS\n";
        $config = $pdo->query("SELECT * FROM business_menu_config WHERE business_id = 7 LIMIT 5");
        foreach ($config as $row) {
            print_r($row);
        }
    }
    
    // Check menu_items
    $tables = $pdo->query("SHOW TABLES LIKE 'menu_items'");
    if ($tables->rowCount() > 0) {
        echo "\n2. menu_items table EXISTS\n";
        $items = $pdo->query("SELECT * FROM menu_items LIMIT 3");
        foreach ($items as $row) {
            echo "ID:" . $row['id'] . " - " . $row['title'] ?? $row['name'] . "\n";
        }
    }
    
    // Check user_menu_permissions
    $tables = $pdo->query("SHOW TABLES LIKE 'user_menu_permissions'");
    if ($tables->rowCount() > 0) {
        echo "\n3. user_menu_permissions table EXISTS\n";
        $perms = $pdo->query("SELECT COUNT(*) as count FROM user_menu_permissions")->fetch();
        echo "Total permissions: " . $perms['count'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
