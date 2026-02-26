<?php
/**
 * ADD PAYROLL MENU TO HOSTING DEVELOPER DATABASE
 * Detects if running on hosting and updates the correct database
 * 
 * Upload to: https://adfsystem.online/add-payroll-menu-hosting.php
 */

header('Content-Type: text/html; charset=utf-8');

// Detect if production or local (check both HTTP_HOST and command-line)
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isProduction = (!empty($httpHost) && 
                strpos($httpHost, 'localhost') === false && 
                strpos($httpHost, '127.0.0.1') === false);

echo "<h2>Adding Payroll Menu...</h2>\n";
echo "<p>Environment: " . ($isProduction ? "🌐 PRODUCTION (Hosting)" : "💻 LOCAL") . "</p>\n";
echo "<p>HTTP_HOST: " . ($httpHost ?: 'CLI/Not set') . "</p>\n";

// Database mapping (same as in config/database.php)
$dbMapping = [
    'adf_system' => 'adfb2574_adf',
    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
    'adf_benscafe' => 'adfb2574_Adf_Bens',
    'adf_demo' => 'adfb2574_demo'
];

// For hosting, we need the actual credentials
if ($isProduction) {
    // Hosting credentials (read from environment or hardcoded for one-time setup)
    $dbHost = 'localhost';
    $dbUser = 'adfb2574_adfsystem';
    $dbPass = '@Nnoc2025';
    $mainDatabase = 'adfb2574_adf'; // This is adf_system on hosting
    
    echo "<p><strong>Target Database:</strong> $mainDatabase</p>\n";
} else {
    // Local credentials
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = '';
    $mainDatabase = 'adf_system';
    
    echo "<p><strong>Target Database:</strong> $mainDatabase (LOCAL)</p>\n";
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$mainDatabase", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<hr>\n";
    
    // Check if Payroll already exists
    $check = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE menu_code = 'payroll'")->fetchColumn();
    
    if ($check > 0) {
        echo "✅ Payroll menu already exists!\n";
        
        // Check if assigned to businesses
        $assigned = $pdo->query("SELECT COUNT(*) FROM business_menu_config WHERE menu_id = (SELECT id FROM menu_items WHERE menu_code = 'payroll')")->fetchColumn();
        echo "✅ Assigned to $assigned business(es)\n";
        
        // Show current menu list
        echo "\n<h3>Current Menus:</h3>\n";
        $menus = $pdo->query("SELECT id, menu_name, menu_code, menu_order FROM menu_items ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr style='background: #f0f0f0; border: 1px solid #ddd;'><th style='padding: 8px; border: 1px solid #ddd;'>Order</th><th style='padding: 8px; border: 1px solid #ddd;'>Menu</th><th style='padding: 8px; border: 1px solid #ddd;'>Code</th></tr>\n";
        foreach ($menus as $menu) {
            echo "<tr style='border: 1px solid #ddd;'>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . $menu['menu_order'] . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($menu['menu_name']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($menu['menu_code']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
        
    } else {
        echo "❌ Payroll menu not found. Adding...\n\n";
        
        // Get max order
        $maxOrder = $pdo->query("SELECT IFNULL(MAX(menu_order), 0) + 1 FROM menu_items")->fetchColumn();
        $maxId = $pdo->query("SELECT IFNULL(MAX(id), 0) + 1 FROM menu_items")->fetchColumn();
        
        // Insert Payroll menu
        $stmt = $pdo->prepare("
            INSERT INTO menu_items (id, menu_code, menu_name, menu_url, menu_icon, menu_order, is_active, description, created_at)
            VALUES (?, 'payroll', 'Payroll', 'modules/payroll/', 'briefcase', ?, 1, '', NOW())
        ");
        $stmt->execute([$maxId, $maxOrder]);
        
        echo "✅ Inserted Payroll menu:\n";
        echo "   - ID: $maxId\n";
        echo "   - Order: $maxOrder\n";
        echo "   - Code: payroll\n";
        echo "   - URL: modules/payroll/\n\n";
        
        // Get all active businesses and assign menu
        $businesses = $pdo->query("SELECT id, business_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($businesses)) {
            echo "Assigning to businesses:\n";
            $stmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
            
            foreach ($businesses as $biz) {
                $stmt->execute([$biz['id'], $maxId]);
                echo "   ✅ " . htmlspecialchars($biz['business_name']) . "\n";
            }
            
            echo "\n✅ Success! Assigned Payroll menu to " . count($businesses) . " business(es)\n";
        } else {
            echo "⚠️ No active businesses found to assign menu to\n";
        }
        
        // Show updated menu list
        echo "\n<h3>Updated Menu List:</h3>\n";
        $menus = $pdo->query("SELECT id, menu_name, menu_code, menu_order FROM menu_items ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr style='background: #f0f0f0; border: 1px solid #ddd;'><th style='padding: 8px; border: 1px solid #ddd;'>Order</th><th style='padding: 8px; border: 1px solid #ddd;'>Menu</th><th style='padding: 8px; border: 1px solid #ddd;'>Code</th></tr>\n";
        foreach ($menus as $menu) {
            $highlight = ($menu['menu_code'] === 'payroll') ? "style='background: #d4edda;'" : "";
            echo "<tr $highlight style='border: 1px solid #ddd;'>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . $menu['menu_order'] . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($menu['menu_name']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($menu['menu_code']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    echo "\n<hr>\n";
    echo "<p style='color: green; font-weight: bold;'>✅ Done! Refresh your Menu Configuration page.</p>\n";
    echo "<p><a href='developer/menus.php' style='color: blue;'>← Go to Menu Configuration</a></p>\n";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>❌ Database Error:</strong></p>\n";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>\n";
    echo "<p>Make sure:</p>\n";
    echo "<ul>\n";
    echo "<li>Database credentials are correct</li>\n";
    echo "<li>You're running this on the hosting server</li>\n";
    echo "<li>The menu_items table exists in the database</li>\n";
    echo "</ul>\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; }
h2, h3 { color: #333; }
table { margin: 20px 0; }
</style>
