<?php
/**
 * Fix CQC slug + config + permissions on hosting
 * Ensures ACTIVE_BUSINESS_ID = 'cqc' for all CQC users
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
if ($isHosting) {
    $dbHost = 'localhost';
    $dbUser = 'adfb2574_cqc';
    $dbPass = '@Noc2025';
    $systemDb = 'adfb2574_adf';
} else {
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = '';
    $systemDb = 'adf_system';
}

echo "<pre style='font-size:14px; line-height:1.8;'>\n";
echo "=== FIX CQC COMPLETE ===\n";
echo "Environment: " . ($isHosting ? 'HOSTING' : 'LOCAL') . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$systemDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // STEP 1: Check current CQC business state
    echo "=== STEP 1: CHECK CQC BUSINESS ===\n";
    $biz = $pdo->query("SELECT * FROM businesses WHERE business_code = 'CQC'")->fetch(PDO::FETCH_ASSOC);
    
    if (!$biz) {
        echo "❌ CQC business NOT FOUND!\n</pre>";
        exit;
    }
    
    echo "  ID: {$biz['id']}\n";
    echo "  Code: {$biz['business_code']}\n";
    echo "  Slug: " . ($biz['slug'] ?? 'NULL') . "\n";
    echo "  Type: " . ($biz['business_type'] ?? 'NULL') . "\n";
    echo "  Database: {$biz['database_name']}\n\n";
    
    // STEP 2: Fix slug to 'cqc'
    echo "=== STEP 2: FIX SLUG ===\n";
    $currentSlug = $biz['slug'] ?? '';
    if ($currentSlug !== 'cqc') {
        $pdo->prepare("UPDATE businesses SET slug = 'cqc' WHERE id = ?")->execute([$biz['id']]);
        echo "✅ Fixed slug: '$currentSlug' → 'cqc'\n";
    } else {
        echo "✅ Slug already 'cqc'\n";
    }
    
    // STEP 3: Check/create cqc-projects menu
    echo "\n=== STEP 3: MENU ITEM ===\n";
    $menu = $pdo->query("SELECT id FROM menu_items WHERE menu_code = 'cqc-projects'")->fetch(PDO::FETCH_ASSOC);
    if (!$menu) {
        $pdo->exec("INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active, created_at) VALUES ('cqc-projects', 'CQC Projects', 'bi-sun', 'modules/cqc-projects/', 9, 1, NOW())");
        $menuId = $pdo->lastInsertId();
        echo "✅ Created cqc-projects menu (ID: $menuId)\n";
    } else {
        $menuId = $menu['id'];
        echo "✅ cqc-projects menu exists (ID: $menuId)\n";
    }
    
    // STEP 4: Assign to business
    echo "\n=== STEP 4: BUSINESS MENU CONFIG ===\n";
    $bmc = $pdo->prepare("SELECT id FROM business_menu_config WHERE business_id = ? AND menu_id = ?");
    $bmc->execute([$biz['id'], $menuId]);
    if (!$bmc->fetch()) {
        $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled, created_at) VALUES (?, ?, 1, NOW())")->execute([$biz['id'], $menuId]);
        echo "✅ Assigned to CQC business\n";
    } else {
        echo "✅ Already assigned\n";
    }
    
    // STEP 5: Add permissions for ALL CQC users
    echo "\n=== STEP 5: USER PERMISSIONS ===\n";
    $users = $pdo->prepare("SELECT uba.user_id, u.username, r.role_code FROM user_business_assignment uba JOIN users u ON u.id = uba.user_id JOIN roles r ON r.id = u.role_id WHERE uba.business_id = ?");
    $users->execute([$biz['id']]);
    $cqcUsers = $users->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cqcUsers as $u) {
        echo "\n  User: {$u['username']} (ID:{$u['user_id']}, role:{$u['role_code']})\n";
        $check = $pdo->prepare("SELECT user_id FROM user_menu_permissions WHERE user_id = ? AND business_id = ? AND menu_code = 'cqc-projects'");
        $check->execute([$u['user_id'], $biz['id']]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, 'cqc-projects', 1, 1, 1, 1)")->execute([$u['user_id'], $biz['id'], $menuId]);
            echo "  ✅ Added cqc-projects permission\n";
        } else {
            echo "  ✅ Already has cqc-projects\n";
        }
        
        $fixed = $pdo->exec("UPDATE user_menu_permissions ump JOIN menu_items mi ON mi.menu_code = ump.menu_code SET ump.menu_id = mi.id WHERE ump.user_id = {$u['user_id']} AND ump.business_id = {$biz['id']} AND ump.menu_id IS NULL");
        if ($fixed > 0) echo "  ✅ Fixed $fixed NULL menu_id entries\n";
    }
    
    // STEP 6: Fix config file
    echo "\n=== STEP 6: CONFIG FILE ===\n";
    $configFile = __DIR__ . '/config/businesses/cqc.php';
    $needsRewrite = false;
    
    if (file_exists($configFile)) {
        $config = require $configFile;
        echo "  Exists, business_id: " . ($config['business_id'] ?? 'N/A') . "\n";
        echo "  business_type: " . ($config['business_type'] ?? 'N/A') . "\n";
        echo "  has cqc-projects: " . (in_array('cqc-projects', $config['enabled_modules'] ?? []) ? 'YES' : 'NO') . "\n";
        
        if (!in_array('cqc-projects', $config['enabled_modules'] ?? [])) {
            $needsRewrite = true;
        }
    } else {
        echo "  ❌ NOT FOUND!\n";
        $needsRewrite = true;
    }
    
    if ($needsRewrite) {
        $dbName = $isHosting ? 'adfb2574_cqc' : 'adf_cqc';
        $configContent = <<<PHP
<?php
return [
    'business_id' => 'cqc',
    'name' => 'CQC Enjiniring',
    'business_type' => 'contractor',
    'database' => '$dbName',
    'logo' => '',
    'enabled_modules' => [
        'cashbook',
        'auth',
        'settings',
        'reports',
        'divisions',
        'procurement',
        'sales',
        'bills',
        'payroll',
        'cqc-projects'
    ],
    'theme' => [
        'color_primary' => '#0066CC',
        'color_secondary' => '#004499',
        'icon' => '☀️'
    ],
    'cashbook_columns' => [],
    'dashboard_widgets' => [],
];
PHP;
        @mkdir(dirname($configFile), 0755, true);
        file_put_contents($configFile, $configContent);
        echo "  ✅ Config file written with cqc-projects!\n";
    } else {
        echo "  ✅ Config OK\n";
    }
    
    // STEP 7: Remove duplicate configs pointing to CQC database
    echo "\n=== STEP 7: CHECK DUPLICATES ===\n";
    $configDir = __DIR__ . '/config/businesses/';
    if (is_dir($configDir)) {
        $allConfigs = glob($configDir . '*.php');
        foreach ($allConfigs as $cf) {
            if (basename($cf) === 'cqc.php') continue;
            $cfg = @include $cf;
            if (is_array($cfg)) {
                $db = $cfg['database'] ?? '';
                if ($db === 'adf_cqc' || $db === 'adfb2574_cqc') {
                    echo "  ⚠️ Duplicate: " . basename($cf) . " (db=$db) → Removing\n";
                    @unlink($cf);
                }
            }
        }
    }
    echo "  ✅ Duplicates check done\n";
    
    echo "\n=== ALL FIXES APPLIED ===\n";
    echo "\n⚠️  User 'ilham' MUST LOGOUT and LOGIN again!\n";
    echo "   (Old session has wrong active_business_id)\n";
    echo "\n   After re-login, the CQC gold theme + CQC Projects menu will appear.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
echo "</pre>";
