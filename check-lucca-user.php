<?php
/**
 * Check User Lucca - Permissions & Business Access
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🔍 Check User Lucca - Permissions & Access</h1>";
echo "<style>
    body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f5f5f5; }
    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 5px; margin: 10px 0; }
    table { background: white; border-collapse: collapse; width: 100%; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
    th { background: #6366f1; color: white; }
    .code { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; font-family: 'Courier New', monospace; margin: 10px 0; }
    h2 { color: #333; margin-top: 30px; border-bottom: 2px solid #6366f1; padding-bottom: 10px; }
</style>";

// Get database from URL or use default
$dbName = $_GET['db'] ?? 'adf_system';

try {
    // First try to connect to specified database
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=$dbName;charset=utf8mb4",
            'root',
            ''
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "<div class='success'>✅ Connected to database: <strong>$dbName</strong></div>";
    } catch (PDOException $e) {
        // If database doesn't exist, show error and suggest checking databases
        echo "<div class='error'>❌ Cannot connect to database: <strong>$dbName</strong></div>";
        echo "<div class='info'>Error: " . $e->getMessage() . "</div>";
        echo "<div class='warning'>
            <p><strong>Please check available databases first:</strong></p>
            <a href='check-databases.php' style='display: inline-block; padding: 10px 20px; background: #6366f1; color: white; text-decoration: none; border-radius: 5px;'>Check Available Databases</a>
        </div>";
        exit;
    }
    
    // ===================================
    // 1. CHECK USER LUCCA
    // ===================================
    echo "<h2>1. User Lucca Information</h2>";
    
    $stmt = $pdo->prepare("
        SELECT u.*, r.role_name, r.role_code 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.username = ?
    ");
    $stmt->execute(['lucca']);
    $lucca = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lucca) {
        echo "<div class='error'>❌ User 'lucca' NOT FOUND in database!</div>";
        echo "<div class='info'>Please create user 'lucca' in Developer Panel → Users</div>";
        exit;
    }
    
    echo "<div class='success'>✅ User 'lucca' found!</div>";
    echo "<table>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    echo "<tr><td>ID</td><td>{$lucca['id']}</td></tr>";
    echo "<tr><td>Username</td><td>{$lucca['username']}</td></tr>";
    echo "<tr><td>Email</td><td>{$lucca['email']}</td></tr>";
    echo "<tr><td>Full Name</td><td>{$lucca['full_name']}</td></tr>";
    echo "<tr><td>Role ID</td><td>{$lucca['role_id']}</td></tr>";
    echo "<tr><td>Role Name</td><td>{$lucca['role_name']}</td></tr>";
    echo "<tr><td>Role Code</td><td><strong>{$lucca['role_code']}</strong></td></tr>";
    echo "<tr><td>Is Active</td><td>" . ($lucca['is_active'] ? '✅ Active' : '❌ Inactive') . "</td></tr>";
    echo "</table>";
    
    $luccaId = $lucca['id'];
    $roleCode = $lucca['role_code'];
    
    // ===================================
    // 2. CHECK ROLE - MUST BE OWNER/ADMIN/DEVELOPER
    // ===================================
    echo "<h2>2. Role Check for Owner Dashboard Access</h2>";
    
    $allowedRoles = ['owner', 'admin', 'developer'];
    $canAccessOwner = in_array(strtolower($roleCode), $allowedRoles);
    
    if ($canAccessOwner) {
        echo "<div class='success'>✅ Role '{$roleCode}' CAN access Owner Dashboard</div>";
        echo "<div class='info'>Allowed roles: owner, admin, developer</div>";
    } else {
        echo "<div class='error'>❌ Role '{$roleCode}' CANNOT access Owner Dashboard</div>";
        echo "<div class='warning'>
            <strong>Solution:</strong><br>
            In Developer Panel → Users:<br>
            1. Edit user 'lucca'<br>
            2. Change Role to: <strong>Owner</strong> (or Admin/Developer)<br>
            3. Save
        </div>";
    }
    
    // ===================================
    // 3. CHECK BUSINESS ASSIGNMENTS
    // ===================================
    echo "<h2>3. Business Access for User Lucca</h2>";
    
    $stmt = $pdo->prepare("
        SELECT b.*, uba.assigned_at 
        FROM user_business_assignment uba
        JOIN businesses b ON uba.business_id = b.id
        WHERE uba.user_id = ?
        ORDER BY b.business_name
    ");
    $stmt->execute([$luccaId]);
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($businesses)) {
        echo "<div class='warning'>⚠️ No business access assigned to user 'lucca'!</div>";
        echo "<div class='info'>
            <strong>Solution:</strong><br>
            In Developer Panel → Business Users:<br>
            1. Select a business<br>
            2. Find user 'lucca'<br>
            3. Assign to businesses<br><br>
            OR use Developer Panel → Permissions
        </div>";
    } else {
        echo "<div class='success'>✅ User has access to " . count($businesses) . " business(es)</div>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Business Name</th><th>Code</th><th>Database</th><th>Assigned At</th></tr>";
        foreach ($businesses as $biz) {
            echo "<tr>";
            echo "<td>{$biz['id']}</td>";
            echo "<td>{$biz['business_name']}</td>";
            echo "<td>{$biz['business_code']}</td>";
            echo "<td>{$biz['database_name']}</td>";
            echo "<td>{$biz['assigned_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // ===================================
    // 4. CHECK MENU PERMISSIONS
    // ===================================
    echo "<h2>4. Menu Permissions</h2>";
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM user_menu_permissions
        WHERE user_id = ?
    ");
    $stmt->execute([$luccaId]);
    $permCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "<div class='info'>📋 Total menu permissions: <strong>$permCount</strong></div>";
    
    if ($permCount > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                b.business_name,
                m.menu_name,
                ump.can_view,
                ump.can_create,
                ump.can_edit,
                ump.can_delete
            FROM user_menu_permissions ump
            JOIN businesses b ON ump.business_id = b.id
            JOIN menu_items m ON ump.menu_id = m.id
            WHERE ump.user_id = ?
            ORDER BY b.business_name, m.menu_name
            LIMIT 10
        ");
        $stmt->execute([$luccaId]);
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table>";
        echo "<tr><th>Business</th><th>Menu</th><th>View</th><th>Create</th><th>Edit</th><th>Delete</th></tr>";
        foreach ($perms as $p) {
            echo "<tr>";
            echo "<td>{$p['business_name']}</td>";
            echo "<td>{$p['menu_name']}</td>";
            echo "<td>" . ($p['can_view'] ? '✅' : '❌') . "</td>";
            echo "<td>" . ($p['can_create'] ? '✅' : '❌') . "</td>";
            echo "<td>" . ($p['can_edit'] ? '✅' : '❌') . "</td>";
            echo "<td>" . ($p['can_delete'] ? '✅' : '❌') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if ($permCount > 10) {
            echo "<div class='info'>... and " . ($permCount - 10) . " more permissions</div>";
        }
    }
    
    // ===================================
    // 5. FINAL CHECK - CAN LOGIN AS OWNER?
    // ===================================
    echo "<h2>5. Final Check - Can Login as Owner?</h2>";
    
    $issues = [];
    
    if (!$lucca['is_active']) {
        $issues[] = "❌ User is INACTIVE - activate in Developer Panel";
    }
    
    if (!$canAccessOwner) {
        $issues[] = "❌ Role '{$roleCode}' cannot access Owner Dashboard - change to 'owner', 'admin', or 'developer'";
    }
    
    if (empty($businesses)) {
        $issues[] = "⚠️ No business access assigned - assign in Developer Panel → Business Users or Permissions";
    }
    
    if (empty($issues)) {
        echo "<div class='success'>
            <h3>✅ ALL CHECKS PASSED!</h3>
            <p><strong>User 'lucca' can now login as owner!</strong></p>
            <br>
            <strong>Login Steps:</strong><br>
            1. Go to: <a href='http://localhost:8081/adf_system/login.php'>http://localhost:8081/adf_system/login.php</a><br>
            2. Username: <code>lucca</code><br>
            3. Password: <code>lucca</code><br>
            4. Click button: <strong>📊 Login Owner</strong><br>
            5. Will redirect to: /modules/owner/dashboard.php
        </div>";
    } else {
        echo "<div class='error'>";
        echo "<h3>❌ ISSUES FOUND:</h3>";
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li>$issue</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
    // ===================================
    // 6. QUICK FIX SCRIPT
    // ===================================
    if (!empty($issues)) {
        echo "<h2>6. Quick Fix</h2>";
        echo "<div class='warning'>";
        echo "<p><strong>Would you like to auto-fix these issues?</strong></p>";
        echo "<p>I can create a script to automatically:</p>";
        echo "<ul>";
        echo "<li>Set role to 'owner'</li>";
        echo "<li>Activate user</li>";
        echo "<li>Assign to all businesses</li>";
        echo "</ul>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Database Error: " . $e->getMessage() . "</div>";
}
?>
