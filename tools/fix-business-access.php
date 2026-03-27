<?php
/**
 * FIX BUSINESS ACCESS - Tool untuk fix business_access otomatis
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<meta charset='UTF-8'>";
echo "<title>Fix Business Access</title>";
echo "<style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .success { color: green; padding: 15px; background: #e8f5e9; border-radius: 5px; margin: 10px 0; }
    .error { color: red; padding: 15px; background: #ffebee; border-radius: 5px; margin: 10px 0; }
    .btn { padding: 12px 24px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 5px; text-decoration: none; display: inline-block; }
    .btn:hover { background: #45a049; }
    .btn-danger { background: #f44336; }
    .btn-danger:hover { background: #da190b; }
    h1 { color: #333; }
    pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background: #f0f0f0; }
</style>";
echo "</head><body>";

echo "<div class='container'>";
echo "<h1>🔧 Fix Business Access Tool</h1>";
echo "<p>Tool ini akan memperbaiki business_access untuk semua user admin/owner</p>";

// Check if fix action
if (isset($_GET['action']) && $_GET['action'] === 'fix') {
    echo "<h2>🔄 Fixing Business Access...</h2>";
    
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get all admin and owner users
        $stmt = $pdo->query("SELECT id, username, role, business_access FROM users WHERE role IN ('admin', 'owner')");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Users Found:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Current Access</th><th>Action</th></tr>";
        
        $fixed = 0;
        $skipped = 0;
        
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>" . ($user['business_access'] ?: 'NULL') . "</td>";
            
            // Check if needs fix
            $currentAccess = json_decode($user['business_access'] ?: '[]', true);
            $needsFix = !is_array($currentAccess) || count($currentAccess) !== 6;
            
            if ($needsFix) {
                // Fix: Set to all 6 businesses
                $newAccess = '[1,2,3,4,5,6]';
                
                // Update main database
                $updateStmt = $pdo->prepare("UPDATE users SET business_access = ? WHERE id = ?");
                $updateStmt->execute([$newAccess, $user['id']]);
                
                // Sync to all business databases
                $databases = [
                    'narayana_benscafe',
                    'narayana_hotel',
                    'narayana_eatmeet',
                    'narayana_pabrikkapal',
                    'narayana_furniture',
                    'narayana_karimunjawa'
                ];
                
                foreach ($databases as $db) {
                    try {
                        $syncStmt = $pdo->prepare("UPDATE {$db}.users SET business_access = ? WHERE id = ?");
                        $syncStmt->execute([$newAccess, $user['id']]);
                    } catch (Exception $e) {
                        // Skip if database doesn't exist
                    }
                }
                
                echo "<td><span style='color:green;'>✅ FIXED to [1,2,3,4,5,6]</span></td>";
                $fixed++;
            } else {
                echo "<td><span style='color:gray;'>⏭️ Already OK</span></td>";
                $skipped++;
            }
            
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<div class='success'>";
        echo "<h3>✅ Fix Completed!</h3>";
        echo "<p><strong>Fixed:</strong> {$fixed} users</p>";
        echo "<p><strong>Skipped:</strong> {$skipped} users (already OK)</p>";
        echo "</div>";
        
        // Show updated users
        $stmt = $pdo->query("SELECT id, username, role, business_access FROM users WHERE role IN ('admin', 'owner')");
        $updatedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Updated Users:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Business Access</th></tr>";
        foreach ($updatedUsers as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td><strong>{$user['business_access']}</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<div class='success'>";
        echo "<h3>🎉 Next Steps:</h3>";
        echo "<ol>";
        echo "<li>Logout: <a href='../logout.php'>../logout.php</a></li>";
        echo "<li>Login: <a href='../owner-login.php'>../owner-login.php</a></li>";
        echo "<li>Test: <a href='diagnostic-owner.php'>Run Diagnostic</a></li>";
        echo "</ol>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<h3>❌ Error!</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
    
} else {
    // Show current status
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT id, username, role, business_access FROM users WHERE role IN ('admin', 'owner')");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>📊 Current Status</h2>";
        
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Business Access</th><th>Status</th></tr>";
        
        $needsFix = 0;
        $isOk = 0;
        
        foreach ($users as $user) {
            $currentAccess = json_decode($user['business_access'] ?: '[]', true);
            $valid = is_array($currentAccess) && count($currentAccess) === 6;
            
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td><strong>{$user['username']}</strong></td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>" . ($user['business_access'] ?: '<span style="color:red;">NULL</span>') . "</td>";
            
            if ($valid) {
                echo "<td><span style='color:green;'>✅ OK</span></td>";
                $isOk++;
            } else {
                echo "<td><span style='color:red;'>❌ NEEDS FIX</span></td>";
                $needsFix++;
            }
            
            echo "</tr>";
        }
        
        echo "</table>";
        
        if ($needsFix > 0) {
            echo "<div class='error'>";
            echo "<h3>⚠️ Issues Found</h3>";
            echo "<p><strong>{$needsFix}</strong> user(s) perlu diperbaiki</p>";
            echo "<p><strong>{$isOk}</strong> user(s) sudah OK</p>";
            echo "</div>";
            
            echo "<h3>🔧 Action Required:</h3>";
            echo "<p><a href='?action=fix' class='btn'>Fix All Business Access</a></p>";
        } else {
            echo "<div class='success'>";
            echo "<h3>✅ All Good!</h3>";
            echo "<p>Semua user admin/owner sudah punya business_access yang benar</p>";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<h3>❌ Database Error!</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
    
    echo "<hr>";
    echo "<h3>🛠️ Other Tools:</h3>";
    echo "<p>";
    echo "<a href='diagnostic-owner.php' class='btn'>Run Diagnostic</a> ";
    echo "<a href='check-session.php' class='btn'>Check Session</a> ";
    echo "<a href='../owner-login.php' class='btn'>Owner Login</a> ";
    echo "<a href='../logout.php' class='btn btn-danger'>Logout</a>";
    echo "</p>";
}

echo "</div>";
echo "</body></html>";
