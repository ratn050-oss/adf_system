<?php
/**
 * Fix Ilham Permissions - Add ALL menu permissions
 * Run on: https://adfsystem.online/fix-ilham-permissions.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>\n";
echo "=== FIX ILHAM PERMISSIONS ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Use system config
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$dbname = DB_NAME;

echo "Using: $user @ $dbname\n\n";

$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get CQC business
$stmt = $pdo->query("SELECT id FROM businesses WHERE business_code = 'CQC'");
$cqcBusiness = $stmt->fetch(PDO::FETCH_ASSOC);
$businessId = $cqcBusiness['id'];
echo "CQC Business ID: $businessId\n\n";

// Fix business_type to contractor
echo "=== STEP 1: FIX BUSINESS TYPE ===\n";
$pdo->exec("UPDATE businesses SET business_type = 'contractor' WHERE id = $businessId");
echo "✅ business_type set to 'contractor'\n\n";

// Get user ilham
$stmt = $pdo->prepare("SELECT id, username, role_code FROM users WHERE username = 'ilham'");
$stmt->execute();
$ilham = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ilham) {
    die("❌ User ilham not found!\n");
}

echo "=== STEP 2: USER INFO ===\n";
echo "  User ID: {$ilham['id']}\n";
echo "  Username: {$ilham['username']}\n";
echo "  Role: {$ilham['role_code']}\n\n";

// Get ALL menu items that should be visible
echo "=== STEP 3: GET ALL MENUS ===\n";
$stmt = $pdo->query("SELECT id, menu_code, menu_name FROM menu_items ORDER BY id");
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($menus) . " menus:\n";
foreach ($menus as $menu) {
    echo "  - {$menu['menu_code']} (ID:{$menu['id']})\n";
}

// Check business_menu_config - which menus are enabled for CQC
echo "\n=== STEP 4: ENABLED MENUS FOR CQC ===\n";
$stmt = $pdo->prepare("
    SELECT mi.id, mi.menu_code, mi.menu_name 
    FROM menu_items mi
    LEFT JOIN business_menu_config bmc ON mi.id = bmc.menu_id AND bmc.business_id = ?
    WHERE bmc.is_enabled = 1 OR bmc.is_enabled IS NULL
    ORDER BY mi.id
");
$stmt->execute([$businessId]);
$enabledMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Enabled menus for CQC: " . count($enabledMenus) . "\n";
foreach ($enabledMenus as $menu) {
    echo "  - {$menu['menu_code']}\n";
}

// ADD/UPDATE all menu permissions for ilham
echo "\n=== STEP 5: ADD ALL PERMISSIONS ===\n";

// Standard menus for CQC business
$menusToEnable = [
    'kasbook',
    'kas-mutasi', 
    'setup-rekening',
    'cqc-projects',
    'kasbook-filter',
    'laporan-keuangan'
];

$userId = $ilham['id'];

foreach ($menusToEnable as $menuCode) {
    // Get menu_id
    $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE menu_code = ?");
    $stmt->execute([$menuCode]);
    $menu = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$menu) {
        // Create the menu if it doesn't exist
        $stmt = $pdo->prepare("INSERT INTO menu_items (menu_code, menu_name, menu_order, icon, is_active) VALUES (?, ?, 10, 'fas fa-folder', 1)");
        $menuName = ucwords(str_replace('-', ' ', $menuCode));
        $stmt->execute([$menuCode, $menuName]);
        $menuId = $pdo->lastInsertId();
        echo "  Created menu: $menuCode (ID:$menuId)\n";
    } else {
        $menuId = $menu['id'];
    }
    
    // Ensure business_menu_config exists
    $stmt = $pdo->prepare("SELECT id FROM business_menu_config WHERE business_id = ? AND menu_id = ?");
    $stmt->execute([$businessId, $menuId]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)")
            ->execute([$businessId, $menuId]);
        echo "  Enabled menu $menuCode for CQC business\n";
    }
    
    // Check if permission already exists
    $stmt = $pdo->prepare("
        SELECT id FROM user_menu_permissions 
        WHERE user_id = ? AND business_id = ? AND (menu_id = ? OR menu_code = ?)
    ");
    $stmt->execute([$userId, $businessId, $menuId, $menuCode]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update to ensure all permissions are ON
        $stmt = $pdo->prepare("
            UPDATE user_menu_permissions 
            SET menu_id = ?, can_view = 1, can_create = 1, can_edit = 1, can_delete = 1
            WHERE id = ?
        ");
        $stmt->execute([$menuId, $existing['id']]);
        echo "  ✅ Updated: $menuCode\n";
    } else {
        // Insert new permission
        $stmt = $pdo->prepare("
            INSERT INTO user_menu_permissions 
            (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete)
            VALUES (?, ?, ?, ?, 1, 1, 1, 1)
        ");
        $stmt->execute([$userId, $businessId, $menuId, $menuCode]);
        echo "  ✅ Added: $menuCode\n";
    }
}

// List final permissions
echo "\n=== STEP 6: FINAL PERMISSIONS ===\n";
$stmt = $pdo->prepare("
    SELECT ump.*, mi.menu_name 
    FROM user_menu_permissions ump
    LEFT JOIN menu_items mi ON ump.menu_id = mi.id
    WHERE ump.user_id = ? AND ump.business_id = ?
");
$stmt->execute([$userId, $businessId]);
$perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "User ilham has " . count($perms) . " permissions:\n";
foreach ($perms as $p) {
    $v = $p['can_view'] ? '✓' : '✗';
    $c = $p['can_create'] ? '✓' : '✗';
    $e = $p['can_edit'] ? '✓' : '✗';
    $d = $p['can_delete'] ? '✓' : '✗';
    echo "  {$p['menu_code']} (ID:{$p['menu_id']}): View=$v Create=$c Edit=$e Delete=$d\n";
}

echo "\n=== DONE ===\n";
echo "\n⚠️  User ilham MUST LOGOUT and LOGIN again!\n";
echo "   Clear browser cache if needed.\n";
echo "</pre>\n";
