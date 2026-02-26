<?php
/**
 * Add Payroll Menu to menu_items table
 * Run this once to add the payroll menu without affecting other menus
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>Adding Payroll Menu...</h3>";
    
    // Check if payroll menu already exists
    $check = $pdo->prepare("SELECT id FROM menu_items WHERE menu_code = 'payroll'");
    $check->execute();
    $existing = $check->fetch();
    
    if ($existing) {
        echo "<p style='color: orange;'>ℹ️ Payroll menu already exists (ID: {$existing['id']})</p>";
    } else {
        // Get next menu order
        $maxOrder = $pdo->query("SELECT MAX(menu_order) as mo FROM menu_items")->fetch()['mo'] ?? 10;
        $nextOrder = $maxOrder + 1;
        
        // Get next ID
        $maxId = $pdo->query("SELECT MAX(id) as mid FROM menu_items")->fetch()['mid'] ?? 11;
        $nextId = $maxId + 1;
        
        // Insert payroll menu
        $stmt = $pdo->prepare("INSERT INTO menu_items (id, menu_code, menu_name, menu_icon, menu_url, menu_order, is_active, description) 
                               VALUES (?, 'payroll', 'Gaji Karyawan', 'currency-dollar', 'modules/payroll/', ?, 1, 'Modul Penggajian Karyawan')");
        $stmt->execute([$nextId, $nextOrder]);
        
        echo "<p style='color: green;'>✅ Payroll menu created with ID: $nextId</p>";
    }
    
    // Assign payroll menu to all businesses
    echo "<h4>Assigning payroll menu to businesses...</h4>";
    
    // Get payroll menu ID
    $payrollMenuId = $pdo->query("SELECT id FROM menu_items WHERE menu_code = 'payroll'")->fetch()['id'];
    
    // Get all businesses
    $businesses = $pdo->query("SELECT id, business_name FROM businesses")->fetchAll();
    
    foreach ($businesses as $biz) {
        // Check if already assigned
        $checkAssign = $pdo->prepare("SELECT id FROM business_menu_config WHERE business_id = ? AND menu_id = ?");
        $checkAssign->execute([$biz['id'], $payrollMenuId]);
        
        if (!$checkAssign->fetch()) {
            $assign = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
            $assign->execute([$biz['id'], $payrollMenuId]);
            echo "<p>✅ Assigned to: {$biz['business_name']}</p>";
        } else {
            echo "<p style='color: gray;'>⏭️ Already assigned to: {$biz['business_name']}</p>";
        }
    }
    
    echo "<hr><p style='color: green; font-weight: bold;'>✅ Done! Payroll menu is now available.</p>";
    echo "<p><a href='developer/menus.php'>Go to Developer → Menu Configuration</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
