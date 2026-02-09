<?php
/**
 * CREATE/FIX Sandra di LOKAL - MASTER Database
 * Pastikan Sandra bisa login di sistem lokal
 */

echo "<h1>🔧 CREATE/FIX Sandra di LOKAL</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    .success { color: white; background: #4CAF50; padding: 15px; margin: 10px 0; border-radius: 5px; }
    .error { color: white; background: #f44336; padding: 15px; margin: 10px 0; border-radius: 5px; }
    .info { background: #e3f2fd; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #2196F3; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-family: 'Courier New'; }
</style>";

$username = 'sandra';
$password = 'admin123';
$fullName = 'Sandra Oktavia';
$email = 'sandra@narayana.com';
$roleId = 3; // Staff role

// STEP 1: Connect to MASTER database
echo "<div class='info'><strong>STEP 1:</strong> Connect to MASTER database (adf_system)</div>";

try {
    $masterPdo = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<div class='success'>✅ Connected to MASTER: adf_system</div>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Connection failed: " . $e->getMessage() . "</div>";
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
    $stmt = $masterPdo->prepare("
        INSERT INTO users (username, password, full_name, email, phone, role_id, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$username, $hashedPassword, $fullName, $email, '081234567890', $roleId]);
    $sandraId = $masterPdo->lastInsertId();
    echo "<div class='success'>✅ Sandra CREATED in MASTER - ID: $sandraId</div>";
} else {
    $stmt = $masterPdo->prepare("
        UPDATE users 
        SET password = ?, full_name = ?, email = ?, role_id = ?, is_active = 1
        WHERE username = ?
    ");
    $stmt->execute([$hashedPassword, $fullName, $email, $roleId, $username]);
    echo "<div class='success'>✅ Sandra UPDATED in MASTER - ID: $sandraId</div>";
}

// STEP 4: Assign permissions to Narayana Hotel
echo "<div class='info'><strong>STEP 4:</strong> Assign permissions for <strong>Narayana Hotel</strong></div>";

// Get Narayana business ID
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
        
        foreach ($menus as $menu) {
            $permStmt->execute([$sandraId, $businessId, $menu['id']]);
        }
        
        echo "<div class='success'>✅ Assigned " . count($menus) . " menus to Sandra for Narayana Hotel</div>";
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

// STEP 5: Create Sandra in BUSINESS database (adf_narayana_hotel)
echo "<div class='info'><strong>STEP 5:</strong> Create/Update Sandra in BUSINESS database (adf_narayana_hotel)</div>";

try {
    $bizPdo = new PDO("mysql:host=localhost;dbname=adf_narayana_hotel", "root", "");
    $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $checkStmt = $bizPdo->prepare("SELECT id FROM users WHERE username = ?");
    $checkStmt->execute([$username]);
    $bizUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($bizUser) {
        $updateStmt = $bizPdo->prepare("
            UPDATE users 
            SET password = ?, full_name = ?, email = ?, role = 'staff', business_access = 'narayana-hotel', is_active = 1
            WHERE username = ?
        ");
        $updateStmt->execute([$hashedPassword, $fullName, $email, $username]);
        echo "<div class='success'>✅ Sandra UPDATED in BUSINESS database</div>";
    } else {
        $insertStmt = $bizPdo->prepare("
            INSERT INTO users (username, password, full_name, email, phone, role, business_access, is_active)
            VALUES (?, ?, ?, ?, ?, 'staff', 'narayana-hotel', 1)
        ");
        $insertStmt->execute([$username, $hashedPassword, $fullName, $email, '081234567890']);
        echo "<div class='success'>✅ Sandra CREATED in BUSINESS database</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Business database error: " . $e->getMessage() . "</div>";
}

// STEP 6: TEST LOGIN
echo "<div class='info'><strong>STEP 6:</strong> Test Login Credentials</div>";

$testStmt = $masterPdo->prepare("SELECT * FROM users WHERE username = ?");
$testStmt->execute([$username]);
$testUser = $testStmt->fetch(PDO::FETCH_ASSOC);

if ($testUser && password_verify($password, $testUser['password'])) {
    echo "<div class='success'>
        <h3>✅ LOGIN TEST PASSED!</h3>
        <p><strong>Username:</strong> <code>$username</code></p>
        <p><strong>Password:</strong> <code>$password</code></p>
        <p><strong>Database:</strong> adf_system (MASTER)</p>
        <p><strong>User ID:</strong> {$testUser['id']}</p>
        <p><strong>Role ID:</strong> {$testUser['role_id']}</p>
    </div>";
    
    // Check accessible businesses
    $bizCheckStmt = $masterPdo->prepare("
        SELECT DISTINCT b.business_name
        FROM businesses b
        JOIN user_menu_permissions p ON b.id = p.business_id
        WHERE p.user_id = ?
    ");
    $bizCheckStmt->execute([$testUser['id']]);
    $businesses = $bizCheckStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='success'>";
    echo "<h4>Sandra can access:</h4>";
    echo "<ul>";
    foreach ($businesses as $biz) {
        echo "<li><strong>{$biz['business_name']}</strong></li>";
    }
    echo "</ul>";
    echo "</div>";
    
} else {
    echo "<div class='error'>❌ LOGIN TEST FAILED - Password verification error</div>";
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
