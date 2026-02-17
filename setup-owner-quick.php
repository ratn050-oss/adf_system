<?php
/**
 * Quick Setup Owner User
 * Script ini membuat user owner dengan cepat untuk testing
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🚀 Quick Setup Owner User</h1>";
echo "<style>
    body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f5f5f5; }
    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .code { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; font-family: 'Courier New', monospace; margin: 10px 0; overflow-x: auto; }
    table { background: white; border-collapse: collapse; width: 100%; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
    th { background: #6366f1; color: white; }
    .btn { display: inline-block; padding: 10px 20px; background: #6366f1; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
    .btn:hover { background: #4f46e5; }
</style>";

try {
    // Connect to database
    $pdo = new PDO(
        'mysql:host=localhost;dbname=adf_narayana_db;charset=utf8mb4',
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='success'>✅ Connected to database: adf_narayana_db</div>";
    
    // ===================================
    // STEP 1: Check if roles table exists
    // ===================================
    echo "<h2>Step 1: Check Roles</h2>";
    
    $stmt = $pdo->query("SELECT * FROM roles ORDER BY id");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($roles)) {
        echo "<div class='error'>❌ No roles found! Please create roles first.</div>";
        exit;
    }
    
    echo "<table>";
    echo "<tr><th>ID</th><th>Role Name</th><th>Role Code</th></tr>";
    foreach ($roles as $role) {
        echo "<tr><td>{$role['id']}</td><td>{$role['role_name']}</td><td>{$role['role_code']}</td></tr>";
    }
    echo "</table>";
    
    // Find owner role
    $ownerRole = null;
    foreach ($roles as $role) {
        if (strtolower($role['role_code']) === 'owner') {
            $ownerRole = $role;
            break;
        }
    }
    
    if (!$ownerRole) {
        echo "<div class='error'>❌ Role 'owner' not found! Please create it first in developer panel.</div>";
        exit;
    }
    
    echo "<div class='success'>✅ Owner role found: ID {$ownerRole['id']}</div>";
    
    // ===================================
    // STEP 2: Check existing owner users
    // ===================================
    echo "<h2>Step 2: Check Existing Users</h2>";
    
    $stmt = $pdo->query("
        SELECT u.*, r.role_name, r.role_code 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        ORDER BY u.id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Full Name</th><th>Role</th><th>Active</th></tr>";
    foreach ($users as $user) {
        $active = $user['is_active'] ? '✅' : '❌';
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['email']}</td>";
        echo "<td>{$user['full_name']}</td>";
        echo "<td>{$user['role_name']}</td>";
        echo "<td>{$active}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if owner user already exists
    $existingOwner = null;
    foreach ($users as $user) {
        if ($user['role_code'] === 'owner') {
            $existingOwner = $user;
            break;
        }
    }
    
    if ($existingOwner) {
        echo "<div class='info'>ℹ️ Owner user already exists: <strong>{$existingOwner['username']}</strong></div>";
        echo "<div class='info'>📧 Email: {$existingOwner['email']}</div>";
        echo "<div class='info'>👤 Name: {$existingOwner['full_name']}</div>";
        echo "<br>";
        echo "<a href='http://localhost:8081/adf_system/login.php' class='btn'>🔐 Go to Login Page</a>";
        echo "<a href='http://localhost:8081/adf_system/modules/owner/dashboard-dev.php' class='btn'>📊 Test Dashboard (Dev Mode)</a>";
        exit;
    }
    
    // ===================================
    // STEP 3: Create owner user
    // ===================================
    echo "<h2>Step 3: Create Owner User</h2>";
    
    $username = 'owner';
    $email = 'owner@adfsystem.local';
    $fullName = 'Business Owner';
    $password = 'owner123';
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, full_name, role_id, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([$username, $email, $hashedPassword, $fullName, $ownerRole['id']]);
    $ownerId = $pdo->lastInsertId();
    
    echo "<div class='success'>✅ Owner user created successfully!</div>";
    echo "<div class='info'>
        <strong>Login Credentials:</strong><br>
        👤 Username: <code>$username</code><br>
        🔑 Password: <code>$password</code><br>
        📧 Email: <code>$email</code><br>
        🆔 User ID: <code>$ownerId</code>
    </div>";
    
    // ===================================
    // STEP 4: Assign business access
    // ===================================
    echo "<h2>Step 4: Assign Business Access</h2>";
    
    $stmt = $pdo->query("SELECT id, business_name, business_code FROM businesses WHERE is_active = 1");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($businesses)) {
        echo "<div class='error'>❌ No active businesses found!</div>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Business Name</th><th>Code</th><th>Access</th></tr>";
        
        foreach ($businesses as $biz) {
            // Give access to all businesses
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO user_business_assignment (user_id, business_id, assigned_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$ownerId, $biz['id']]);
                
                echo "<tr>";
                echo "<td>{$biz['id']}</td>";
                echo "<td>{$biz['business_name']}</td>";
                echo "<td>{$biz['business_code']}</td>";
                echo "<td>✅ Granted</td>";
                echo "</tr>";
            } catch (Exception $e) {
                echo "<tr>";
                echo "<td>{$biz['id']}</td>";
                echo "<td>{$biz['business_name']}</td>";
                echo "<td>{$biz['business_code']}</td>";
                echo "<td>❌ Error</td>";
                echo "</tr>";
            }
        }
        echo "</table>";
        
        echo "<div class='success'>✅ Business access granted to all active businesses</div>";
    }
    
    // ===================================
    // FINAL: Login instructions
    // ===================================
    echo "<h2>🎉 Setup Complete!</h2>";
    echo "<div class='success'>
        <strong>Owner user created successfully!</strong><br><br>
        
        <strong>Next Steps:</strong><br>
        1. Go to login page<br>
        2. Enter username: <code>owner</code><br>
        3. Enter password: <code>owner123</code><br>
        4. Click button <strong>📊 Login Owner</strong><br>
        5. You will be redirected to Owner Dashboard
    </div>";
    
    echo "<div class='code'>
# Login URL:
http://localhost:8081/adf_system/login.php

# Direct Dashboard URL (after login):
http://localhost:8081/adf_system/modules/owner/dashboard.php

# Dev/Test Dashboard (no login required):
http://localhost:8081/adf_system/modules/owner/dashboard-dev.php
    </div>";
    
    echo "<br>";
    echo "<a href='http://localhost:8081/adf_system/login.php' class='btn'>🔐 Go to Login Page</a>";
    echo "<a href='http://localhost:8081/adf_system/modules/owner/dashboard-dev.php' class='btn'>📊 Test Dashboard (Dev Mode)</a>";
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Database Error: " . $e->getMessage() . "</div>";
    echo "<div class='info'>
        <strong>Possible solutions:</strong><br>
        1. Check if database 'adf_narayana_db' exists<br>
        2. Check MySQL connection (host, user, password)<br>
        3. Check if tables exist (users, roles, businesses)
    </div>";
}
?>
