<?php
/**
 * Diagnose CQC menu/dashboard issues on hosting
 */
$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
if ($isHosting) {
    $dbHost = 'localhost';
    $dbUser = 'adfb2574_adfsystem';
    $dbPass = '@Nnoc2025';
    $systemDb = 'adfb2574_adf';
} else {
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = '';
    $systemDb = 'adf_system';
}

echo "<pre>\n";
echo "=== CQC HOSTING DIAGNOSTIC ===\n";
echo "Environment: " . ($isHosting ? 'HOSTING' : 'LOCAL') . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Check 1: Git HEAD
echo "=== 1. GIT HEAD ===\n";
$gitHead = trim(shell_exec('git rev-parse --short HEAD 2>&1') ?? 'N/A');
$gitLog = trim(shell_exec('git log --oneline -3 2>&1') ?? 'N/A');
echo "HEAD: $gitHead\n";
echo "Recent commits:\n$gitLog\n\n";

// Check 2: header.php CQC line
echo "=== 2. HEADER.PHP - CQC check ===\n";
$headerFile = __DIR__ . '/includes/header.php';
if (file_exists($headerFile)) {
    $headerContent = file_get_contents($headerFile);
    if (strpos($headerContent, "hasPermission('cqc-projects')") !== false) {
        echo "✅ Uses hasPermission('cqc-projects') (NEW code)\n";
    } elseif (strpos($headerContent, "isModuleEnabled('cqc-projects')") !== false) {
        echo "⚠️ Uses isModuleEnabled('cqc-projects') (OLD code)\n";
    } else {
        echo "❌ No CQC check found in header!\n";
    }
    
    // Check the exact line
    $lines = explode("\n", $headerContent);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'cqc-projects') !== false || stripos($line, 'cqc_projects') !== false) {
            echo "  Line " . ($i+1) . ": " . trim($line) . "\n";
        }
    }
} else {
    echo "❌ header.php not found!\n";
}

// Check 3: index.php CQC check
echo "\n=== 3. INDEX.PHP - isCQC check ===\n";
$indexFile = __DIR__ . '/index.php';
if (file_exists($indexFile)) {
    $indexContent = file_get_contents($indexFile);
    if (strpos($indexContent, '$isCQC') !== false) {
        echo "✅ Has \$isCQC variable\n";
    } else {
        echo "❌ No \$isCQC in index.php!\n";
    }
    
    if (strpos($indexContent, 'Semua Proyek') !== false) {
        echo "✅ Has 'Semua Proyek' table\n";
    } else {
        echo "❌ No 'Semua Proyek' section in index.php!\n";
    }
    
    if (strpos($indexContent, 'projectPieChart') !== false) {
        echo "✅ Has project pie charts\n";
    } else {
        echo "❌ No project pie charts!\n";
    }
}

// Check 4: Database
echo "\n=== 4. DATABASE - menu_items ===\n";
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$systemDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $menus = $pdo->query("SELECT id, menu_code, menu_name FROM menu_items ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($menus as $m) {
        $mark = ($m['menu_code'] === 'cqc-projects') ? '👈 CQC' : '';
        echo "  ID:{$m['id']} | {$m['menu_code']} | {$m['menu_name']} $mark\n";
    }
    
    echo "\n=== 5. CQC Business Menu Config ===\n";
    $bmc = $pdo->query("SELECT bmc.menu_id, m.menu_code FROM business_menu_config bmc JOIN menu_items m ON m.id = bmc.menu_id WHERE bmc.business_id = 7")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bmc as $b) {
        echo "  menu_id:{$b['menu_id']} | {$b['menu_code']}\n";
    }
    
    echo "\n=== 6. User Permissions for CQC (business_id=7) ===\n";
    $perms = $pdo->query("SELECT ump.user_id, u.username, ump.menu_code, ump.menu_id FROM user_menu_permissions ump JOIN users u ON u.id = ump.user_id WHERE ump.business_id = 7 ORDER BY u.username, ump.menu_code")->fetchAll(PDO::FETCH_ASSOC);
    $currentUser = '';
    foreach ($perms as $p) {
        if ($p['username'] !== $currentUser) {
            echo "\n  User: {$p['username']} (ID:{$p['user_id']})\n";
            $currentUser = $p['username'];
        }
        $mark = ($p['menu_code'] === 'cqc-projects') ? ' 👈' : '';
        echo "    - {$p['menu_code']} (menu_id:{$p['menu_id']})$mark\n";
    }
    
} catch (Exception $e) {
    echo "❌ DB Error: " . $e->getMessage() . "\n";
}

// Check 5: Config file
echo "\n=== 7. CONFIG FILE ===\n";
$cqcConfigFile = __DIR__ . '/config/businesses/cqc.php';
if (file_exists($cqcConfigFile)) {
    $cqcConfig = require $cqcConfigFile;
    echo "✅ cqc.php exists\n";
    echo "  enabled_modules: " . implode(', ', $cqcConfig['enabled_modules'] ?? []) . "\n";
} else {
    echo "❌ config/businesses/cqc.php NOT FOUND!\n";
}

echo "\n=== DONE ===\n";
echo "</pre>";
?>
