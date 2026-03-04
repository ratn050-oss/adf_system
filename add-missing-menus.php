<?php
/**
 * Add Missing Menu Items to Database
 * Run this script once on hosting to add missing menus
 */

require_once __DIR__ . '/public/includes/config.php';

echo "<h2>Adding Missing Menu Items</h2>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $menusToAdd = [
        ['bills', 'Tagihan', 'modules/bills/', 7],
        ['owner', 'Owner Monitoring', 'modules/owner/', 15],
        ['admin', 'Admin Panel', 'modules/admin/', 16],
    ];
    
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
