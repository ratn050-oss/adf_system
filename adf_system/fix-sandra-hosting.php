<?php
/**
 * CREATE/FIX Sandra di HOSTING - MASTER Database  
 * Uses CORRECT database credentials from config.php
 */

echo "<h1>🔧 CREATE/FIX Sandra di HOSTING</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    .success { color: white; background: #4CAF50; padding: 15px; margin: 10px 0; border-radius: 5px; }
    .error { color: white; background: #f44336; padding: 15px; margin: 10px 0; border-radius: 5px; }
    .info { background: #e3f2fd; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #2196F3; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-family: 'Courier New'; }
</style>";

// HOSTING credentials (from config.php)
$host = 'localhost';
$masterDbName = 'adfb2574_adf';
$masterUser = 'adfb2574_adfsystem';
$masterPassword = '@Nnoc2025';

$username = 'sandra';
$password = 'admin123';
$fullName = 'Sandra Oktavia';
$email = 'sandra@narayana.com';
$roleId = 3; // Staff role

// STEP 1: Connect to MASTER database
echo "<div class='info'><strong>STEP 1:</strong> Connect to MASTER database (adfb2574_adf)</div>";
echo "<p>Host: <code>$host</code></p>";
echo "<p>Database: <code>$masterDbName</code></p>";
echo "<p>User: <code>$masterUser</code></p>";

try {
    $masterPdo = new PDO(
        "mysql:host=$host;dbname=$masterDbName;charset=utf8mb4",
        $masterUser,
        $masterPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<div class='success'>✅ Connected to MASTER: $masterDbName</div>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Connection failed: " . $e->getMessage() . "</div>";
    echo "<p>Credentials used:</p>";
    echo "<ul>";
    echo "<li>User: $masterUser</li>";
    echo "<li>Database: $masterDbName</li>";
    echo "</ul>";
    exit;
}

// STEP 2: Check if Sandra exists
echo "<div class='info'><strong>STEP 2:</strong> Check if Sandra exists in MASTER</div>";

$stmt = $masterPdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo "<div class='success'>✅ Sandra already exists (ID: {$existing['id']})</div>";
    $sandraId = $existing['id'];
    $action = 'UPDATE';
} else {
    echo "<div class='info'>⚠️ Sandra NOT found - will CREATE new user</div>";
    $action = 'CREATE';
}

// STEP 3: Create or Update Sandra
echo "<div class='info'><strong>STEP 3:</strong> $action Sandra in MASTER database</div>";

$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

if ($action === 'CREATE') {
    try {
        $stmt = $masterPdo->prepare("
            INSERT INTO users (username, password, full_name, email, phone, role_id, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$username, $hashedPassword, $fullName, $email, '081234567890', $roleId]);
        $sandraId = $masterPdo->lastInsertId();
        echo "<div class='success'>✅ Sandra CREATED in MASTER - ID: $sandraId</div>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ CREATE failed: " . $e->getMessage() . "</div>";
        exit;
    }
} else {
    try {
        $stmt = $masterPdo->prepare("
            UPDATE users 
            SET password = ?, full_name = ?, email = ?, role_id = ?, is_active = 1
            WHERE username = ?
        ");
        $stmt->execute([$hashedPassword, $fullName, $email, $roleId, $username]);
        echo "<div class='success'>✅ Sandra UPDATED in MASTER - ID: $sandraId</div>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ UPDATE failed: " . $e->getMessage() . "</div>";
    }
}

// STEP 4: Assign permissions to Narayana Hotel
echo "<div class='info'><strong>STEP 4:</strong> Assign permissions for <strong>Narayana Hotel</strong></div>";

// Get Narayana business ID
try {
    $bizStmt = $masterPdo->prepare("SELECT id FROM businesses WHERE business_code = 'NARAYANAHOTEL'");
    $bizStmt->execute();
    $narayanaBiz = $bizStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($narayanaBiz) {
        $businessId = $narayanaBiz['id'];
        echo "<div class='success'>✅ Found Narayana Hotel - Business ID: $businessId</div>";
        
        // Delete old permissions for this business
        $delStmt = $masterPdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?");
        $delStmt->execute([$sandraId, $businessId]);
        
        // Get all enabled menus for Narayana
        $menuStmt = $masterPdo->prepare("
            SELECT m.id, m.name 
            FROM menu_items m
            JOIN business_menu_config bmc ON m.id = bmc.menu_id
            WHERE bmc.business_id = ? AND bmc.is_enabled = 1
        ");
        $menuStmt->execute([$businessId]);
        $menus = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($menus)) {
            $permStmt = $masterPdo->prepare("
                INSERT INTO user_menu_permissions (user_id, business_id, menu_id, can_view, can_create, can_edit, can_delete)
                VALUES (?, ?, ?, 1, 1, 1, 1)
            ");
            
            $successCount = 0;
            foreach ($menus as $menu) {
                $permStmt->execute([$sandraId, $businessId, $menu['id']]);
                $successCount++;
            }
            
            echo "<div class='success'>✅ Assigned $successCount menus to Sandra for Narayana Hotel</div>";
            echo "<ul>";
            foreach ($menus as $menu) {
                echo "<li>{$menu['name']}</li>";
            }
            echo "</ul>";
        } else {
            echo "<div class='error'>❌ No enabled menus found for Narayana Hotel</div>";
        }
        
        // Add to user_business_assignment
        $assignStmt = $masterPdo->prepare("
            INSERT INTO user_business_assignment (user_id, business_id, assigned_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE assigned_at = NOW()
        ");
        $assignStmt->execute([$sandraId, $businessId]);
        echo "<div class='success'>✅ Added Sandra to business assignment</div>";
        
    } else {
        echo "<div class='error'>❌ Narayana Hotel business not found!</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Permission assignment error: " . $e->getMessage() . "</div>";
}

// STEP 5: TEST LOGIN
echo "<div class='info'><strong>STEP 5:</strong> Test Login Credentials</div>";

try {
    $testStmt = $masterPdo->prepare("SELECT * FROM users WHERE username = ?");
    $testStmt->execute([$username]);
    $testUser = $testStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($testUser && password_verify($password, $testUser['password'])) {
        echo "<div class='success'>
            <h3>✅ LOGIN TEST PASSED!</h3>
            <p><strong>Username:</strong> <code>$username</code></p>
            <p><strong>Password:</strong> <code>$password</code></p>
            <p><strong>Database:</strong> $masterDbName (MASTER)</p>
            <p><strong>User ID:</strong> {$testUser['id']}</p>
            <p><strong>Role ID:</strong> {$testUser['role_id']}</p>
            <p><strong>Active:</strong> " . ($testUser['is_active'] ? 'YES ✅' : 'NO ❌') . "</p>
        </div>";
        
        // Check accessible businesses
        $bizCheckStmt = $masterPdo->prepare("
            SELECT DISTINCT b.business_name, COUNT(p.menu_id) as menu_count
            FROM businesses b
            JOIN user_menu_permissions p ON b.id = p.business_id
            WHERE p.user_id = ?
            GROUP BY b.id
        ");
        $bizCheckStmt->execute([$testUser['id']]);
        $businesses = $bizCheckStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($businesses)) {
            echo "<div class='success'>";
            echo "<h4>Sandra can access:</h4>";
            echo "<ul>";
            foreach ($businesses as $biz) {
                echo "<li><strong>{$biz['business_name']}</strong> - {$biz['menu_count']} menus</li>";
            }
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div class='error'>❌ No business permissions found!</div>";
        }
        
    } else {
        echo "<div class='error'>❌ LOGIN TEST FAILED - Password verification error</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Test login error: " . $e->getMessage() . "</div>";
}

echo "<hr>";
echo "<div class='info'>";
echo "<h3>📋 NEXT STEPS:</h3>";
echo "<ol>";
echo "<li>Test login at: <a href='login.php' target='_blank' style='color: blue; font-weight: bold;'>login.php</a></li>";
echo "<li>Username: <code>sandra</code></li>";
echo "<li>Password: <code>admin123</code></li>";
echo "<li>Expected: Login sukses, bisa akses Narayana Hotel</li>";
echo "</ol>";
echo "</div>";
?>
