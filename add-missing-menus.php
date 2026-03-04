<?php
/**
 * Add Missing Menu Items to Database + Enable for All Businesses
 * Run this script once on hosting to add missing menus
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Adding Missing Menu Items + Enabling for Businesses</h2>";

// Hosting database credentials
$dbHost = 'localhost';
$dbUser = 'adfb2574_adfsystem';
$dbPass = '@Nnoc2025';
$dbName = 'adfb2574_adf';  // Master database on hosting

try {
    $masterDb = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p>Connected to: {$dbName}</p>";
    
    // Menus to add with their details
    $menusToAdd = [
        ['bills', 'Tagihan', 'modules/bills/', 7],
        ['owner', 'Owner Monitoring', 'modules/owner/', 15],
        ['admin', 'Admin Panel', 'modules/admin/', 16],
        ['divisions', 'Kelola Divisi', 'modules/divisions/', 3],
        ['frontdesk', 'Frontdesk', 'modules/frontdesk/', 4],
        ['project', 'Project', 'modules/project/', 10],
        ['investor', 'Investor', 'modules/investor/', 9],
        ['payroll', 'Payroll', 'modules/payroll/', 14],
    ];
    
    echo "<h3>Step 1: Add missing menus</h3>";
    
    foreach ($menusToAdd as $menu) {
        list($code, $name, $url, $order) = $menu;
        
        // Check if menu already exists
        $stmt = $masterDb->prepare("SELECT id FROM menu_items WHERE menu_code = ?");
        $stmt->execute([$code]);
        
        if ($stmt->fetch()) {
            echo "<p>✓ Menu <strong>{$name}</strong> already exists</p>";
        } else {
            // Insert new menu
            $stmt = $masterDb->prepare("INSERT INTO menu_items (menu_code, menu_name, menu_url, menu_order, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$code, $name, $url, $order]);
            echo "<p>✅ Added menu: <strong>{$name}</strong> ({$code}) → {$url}</p>";
        }
    }
    
    echo "<h3>Step 2: Enable menus for all businesses</h3>";
    
    // Get all businesses
    $bizStmt = $masterDb->query("SELECT id, business_name FROM businesses WHERE is_active = 1");
    $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all menus
    $menuStmt = $masterDb->query("SELECT id, menu_code, menu_name FROM menu_items WHERE is_active = 1");
    $menus = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($businesses as $biz) {
        echo "<p><strong>{$biz['business_name']}</strong>: ";
        $added = 0;
        
        foreach ($menus as $menu) {
            // Check if already in business_menu_config
            $stmt = $masterDb->prepare("SELECT id FROM business_menu_config WHERE business_id = ? AND menu_id = ?");
            $stmt->execute([$biz['id'], $menu['id']]);
            
            if (!$stmt->fetch()) {
                // Add to business_menu_config
                $stmt = $masterDb->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled, created_at) VALUES (?, ?, 1, NOW())");
                $stmt->execute([$biz['id'], $menu['id']]);
                $added++;
            }
        }
        
        if ($added > 0) {
            echo "✅ Enabled {$added} new menus";
        } else {
            echo "✓ All menus already configured";
        }
        echo "</p>";
    }
    
    echo "<h3>Current Menu Items:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>Order</th><th>Name</th><th>Code</th><th>URL</th><th>Active</th></tr>";
    
    $stmt = $masterDb->query("SELECT menu_order, menu_name, menu_code, menu_url, is_active FROM menu_items ORDER BY menu_order");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $active = $row['is_active'] ? '✓' : '✗';
        echo "<tr>";
        echo "<td>{$row['menu_order']}</td>";
        echo "<td>{$row['menu_name']}</td>";
        echo "<td>{$row['menu_code']}</td>";
        echo "<td>{$row['menu_url']}</td>";
        echo "<td>{$active}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<p style='color: green; font-weight: bold;'>✅ Done! You can delete this file now.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
