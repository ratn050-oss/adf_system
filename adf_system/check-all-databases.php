<?php
/**
 * COMPREHENSIVE DATABASE CHECK
 * Check semua database - MASTER vs BUSINESS databases
 */

echo "<h1>🔍 COMPREHENSIVE DATABASE CHECK</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
    th { background: #4CAF50; color: white; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }
</style>";

// ====================
// DATABASE 1: MASTER (adf_system) - INI UNTUK LOGIN
// ====================
echo "<div class='section'>";
echo "<h2>📊 DATABASE 1: MASTER - adf_system (LOGIN DATABASE)</h2>";
echo "<p><strong>Fungsi:</strong> Sistem login membaca dari database ini!</p>";

try {
    $masterPdo = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✅ Connected to MASTER database: adf_system</p>";
    
    // Check Sandra in MASTER
    echo "<h3>Check User 'sandra' di MASTER database:</h3>";
    $stmt = $masterPdo->query("SELECT * FROM users WHERE username = 'sandra'");
    $sandra = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sandra) {
        echo "<p class='success'>✅ Sandra FOUND in MASTER database</p>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        foreach ($sandra as $key => $value) {
            if ($key === 'password') {
                echo "<tr><td>$key</td><td>" . substr($value, 0, 30) . "...</td></tr>";
            } else {
                echo "<tr><td>$key</td><td>$value</td></tr>";
            }
        }
        echo "</table>";
        
        // Test password
        echo "<h4>🔐 Password Test:</h4>";
        if (password_verify('admin123', $sandra['password'])) {
            echo "<p class='success'>✅ Password 'admin123' CORRECT (password_verify)</p>";
        } else if (md5('admin123') === $sandra['password']) {
            echo "<p class='warning'>⚠️ Password uses MD5 (old method)</p>";
        } else {
            echo "<p class='error'>❌ Password 'admin123' INCORRECT</p>";
        }
        
        // Check permissions in MASTER
        echo "<h4>🔑 Permissions in MASTER:</h4>";
        $permStmt = $masterPdo->prepare("
            SELECT b.business_name, COUNT(p.menu_id) as menus
            FROM user_menu_permissions p
            JOIN businesses b ON p.business_id = b.id
            WHERE p.user_id = ?
            GROUP BY b.id
        ");
        $permStmt->execute([$sandra['id']]);
        $perms = $permStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($perms)) {
            echo "<table>";
            echo "<tr><th>Business</th><th>Menu Count</th></tr>";
            foreach ($perms as $p) {
                echo "<tr><td>{$p['business_name']}</td><td>{$p['menus']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ NO permissions found</p>";
        }
        
    } else {
        echo "<p class='error'>❌ Sandra NOT FOUND in MASTER database</p>";
        echo "<p class='warning'>⚠️ LOGIN AKAN GAGAL karena login system cari user di database ini!</p>";
    }
    
    // List all users in MASTER
    echo "<h3>All Users in MASTER database:</h3>";
    $allUsers = $masterPdo->query("SELECT id, username, full_name, email, role_id, is_active FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role ID</th><th>Active</th></tr>";
    foreach ($allUsers as $u) {
        echo "<tr>";
        echo "<td>{$u['id']}</td>";
        echo "<td><strong>{$u['username']}</strong></td>";
        echo "<td>{$u['full_name']}</td>";
        echo "<td>{$u['email']}</td>";
        echo "<td>{$u['role_id']}</td>";
        echo "<td>" . ($u['is_active'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ====================
// DATABASE 2: NARAYANA HOTEL (business database)
// ====================
echo "<div class='section'>";
echo "<h2>📊 DATABASE 2: BUSINESS - adf_narayana_hotel</h2>";
echo "<p><strong>Fungsi:</strong> Database untuk operasional Narayana Hotel</p>";

try {
    $narayanaPdo = new PDO("mysql:host=localhost;dbname=adf_narayana_hotel", "root", "");
    $narayanaPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✅ Connected to BUSINESS database: adf_narayana_hotel</p>";
    
    // Check Sandra in NARAYANA
    echo "<h3>Check User 'sandra' di NARAYANA database:</h3>";
    $stmt = $narayanaPdo->query("SELECT * FROM users WHERE username = 'sandra'");
    $sandraBiz = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sandraBiz) {
        echo "<p class='success'>✅ Sandra FOUND in NARAYANA database</p>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        foreach ($sandraBiz as $key => $value) {
            if ($key === 'password') {
                echo "<tr><td>$key</td><td>" . substr($value, 0, 30) . "...</td></tr>";
            } else {
                echo "<tr><td>$key</td><td>$value</td></tr>";
            }
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ Sandra NOT FOUND in NARAYANA database</p>";
    }
    
    // List all users in NARAYANA
    echo "<h3>All Users in NARAYANA database:</h3>";
    $allUsersBiz = $narayanaPdo->query("SELECT id, username, full_name, email, role, is_active FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Active</th></tr>";
    foreach ($allUsersBiz as $u) {
        echo "<tr>";
        echo "<td>{$u['id']}</td>";
        echo "<td><strong>{$u['username']}</strong></td>";
        echo "<td>{$u['full_name']}</td>";
        echo "<td>{$u['email']}</td>";
        echo "<td>{$u['role']}</td>";
        echo "<td>" . ($u['is_active'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ====================
// DATABASE 3: BEN'S CAFE (business database)
// ====================
echo "<div class='section'>";
echo "<h2>📊 DATABASE 3: BUSINESS - adf_Adf_Bens</h2>";
echo "<p><strong>Fungsi:</strong> Database untuk operasional Ben's Cafe</p>";

try {
    $bensPdo = new PDO("mysql:host=localhost;dbname=adf_Adf_Bens", "root", "");
    $bensPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✅ Connected to BUSINESS database: adf_Adf_Bens</p>";
    
    // Check Sandra in BENS
    echo "<h3>Check User 'sandra' di BENS database:</h3>";
    $stmt = $bensPdo->query("SELECT * FROM users WHERE username = 'sandra'");
    $sandraBens = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sandraBens) {
        echo "<p class='success'>✅ Sandra FOUND in BENS database</p>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        foreach ($sandraBens as $key => $value) {
            if ($key === 'password') {
                echo "<tr><td>$key</td><td>" . substr($value, 0, 30) . "...</td></tr>";
            } else {
                echo "<tr><td>$key</td><td>$value</td></tr>";
            }
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ Sandra NOT FOUND in BENS database</p>";
    }
    
    // List all users in BENS
    echo "<h3>All Users in BENS database:</h3>";
    $allUsersBens = $bensPdo->query("SELECT id, username, full_name, email, role, is_active FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Active</th></tr>";
    foreach ($allUsersBens as $u) {
        echo "<tr>";
        echo "<td>{$u['id']}</td>";
        echo "<td><strong>{$u['username']}</strong></td>";
        echo "<td>{$u['full_name']}</td>";
        echo "<td>{$u['email']}</td>";
        echo "<td>{$u['role']}</td>";
        echo "<td>" . ($u['is_active'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ====================
// SUMMARY & DIAGNOSIS
// ====================
echo "<div class='section'>";
echo "<h2>📋 DIAGNOSIS & SOLUTION</h2>";

echo "<h3>❗ CRITICAL UNDERSTANDING:</h3>";
echo "<ol>";
echo "<li><strong>LOGIN SYSTEM:</strong> File <code>login.php</code> membaca user dari <strong>MASTER database (adf_system)</strong></li>";
echo "<li><strong>BUSINESS OPERATIONS:</strong> Setelah login, sistem switch ke <strong>BUSINESS database (adf_narayana_hotel atau adf_Adf_Bens)</strong></li>";
echo "<li><strong>USER MUST EXIST IN BOTH:</strong> User harus ada di MASTER (untuk login) DAN di BUSINESS database (untuk operasional)</li>";
echo "</ol>";

echo "<h3>✅ EXPECTED BEHAVIOR:</h3>";
echo "<ul>";
echo "<li>Sandra di MASTER database (<code>adf_system</code>) dengan <code>role_id</code></li>";
echo "<li>Sandra di BUSINESS database (<code>adf_narayana_hotel</code>) dengan <code>role</code></li>";
echo "<li>Sandra memiliki permissions di <code>user_menu_permissions</code> table (MASTER)</li>";
echo "</ul>";

echo "<h3>🔧 NEXT STEPS:</h3>";
echo "<p>1. Cek hasil di atas - apakah Sandra ada di MASTER?</p>";
echo "<p>2. Jika TIDAK ada di MASTER, user creation gagal</p>";
echo "<p>3. Jika ada di MASTER tapi password salah, perlu reset password</p>";
echo "<p>4. Test login: <a href='login.php' style='color: blue; font-weight: bold;'>login.php</a> dengan sandra/admin123</p>";

echo "</div>";
?>
