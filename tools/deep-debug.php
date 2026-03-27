<?php
/**
 * Deep Login Debug - Step by Step Analysis
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><style>
body { font-family: monospace; padding: 20px; background: #f5f5f5; }
.step { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #4CAF50; }
.error { border-left-color: #f44336; }
.success { color: #4CAF50; font-weight: bold; }
.fail { color: #f44336; font-weight: bold; }
pre { background: #f9f9f9; padding: 10px; overflow-x: auto; }
</style></head><body>";

echo "<h1>🔍 Deep Login Debug Analysis</h1>";

// Step 1: Check session
echo "<div class='step'>";
echo "<h3>Step 1: Session Check</h3>";
session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p class='success'>✅ Session is active</p>";
    echo "<p>Session ID: " . session_id() . "</p>";
} else {
    echo "<p class='fail'>❌ Session failed</p>";
}
echo "</div>";

// Step 2: Check config files
echo "<div class='step'>";
echo "<h3>Step 2: Config Files Check</h3>";
if (file_exists('../config/config.php')) {
    echo "<p class='success'>✅ config.php exists</p>";
    require_once '../config/config.php';
} else {
    echo "<p class='fail'>❌ config.php not found</p>";
}

if (file_exists('../config/database.php')) {
    echo "<p class='success'>✅ database.php exists</p>";
    require_once '../config/database.php';
} else {
    echo "<p class='fail'>❌ database.php not found</p>";
}
echo "</div>";

// Step 3: Database connection
echo "<div class='step'>";
echo "<h3>Step 3: Database Connection</h3>";
try {
    $db = Database::getInstance();
    echo "<p class='success'>✅ Database connection successful</p>";
    
    // Test query
    $test = $db->query("SELECT 1 as test");
    echo "<p class='success'>✅ Test query executed</p>";
} catch (Exception $e) {
    echo "<p class='fail'>❌ Database error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
echo "</div>";

// Step 4: Check users table
echo "<div class='step'>";
echo "<h3>Step 4: Users Table Check</h3>";
try {
    $users = $db->fetchAll("SELECT id, username, role FROM users LIMIT 5");
    echo "<p class='success'>✅ Users table accessible</p>";
    echo "<p>Total users found: " . count($users) . "</p>";
    echo "<pre>";
    print_r($users);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Step 5: Test specific user
echo "<div class='step'>";
echo "<h3>Step 5: Check devadmin User</h3>";
$username = 'devadmin';
$password = 'dev123';

try {
    $user = $db->fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
    
    if ($user) {
        echo "<p class='success'>✅ User '$username' found</p>";
        echo "<pre>";
        echo "ID: " . $user['id'] . "\n";
        echo "Username: " . $user['username'] . "\n";
        echo "Full Name: " . $user['full_name'] . "\n";
        echo "Role: " . $user['role'] . "\n";
        echo "Password (DB): " . $user['password'] . "\n";
        echo "Expected Hash: " . md5($password) . "\n";
        echo "Match: " . ($user['password'] === md5($password) ? 'YES ✅' : 'NO ❌') . "\n";
        echo "</pre>";
    } else {
        echo "<p class='fail'>❌ User '$username' not found</p>";
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Step 6: Test login query
echo "<div class='step'>";
echo "<h3>Step 6: Test Login Query</h3>";
try {
    $loginUser = $db->fetchOne(
        "SELECT * FROM users WHERE username = ? AND password = MD5(?)",
        [$username, $password]
    );
    
    if ($loginUser) {
        echo "<p class='success'>✅ Login query successful!</p>";
        echo "<pre>";
        print_r($loginUser);
        echo "</pre>";
        
        // Check role
        if (in_array($loginUser['role'], ['admin', 'superadmin', 'owner'])) {
            echo "<p class='success'>✅ Role check passed</p>";
        } else {
            echo "<p class='fail'>❌ Role check failed: " . $loginUser['role'] . "</p>";
        }
    } else {
        echo "<p class='fail'>❌ Login query returned no results</p>";
        
        // Debug: try different combinations
        echo "<p><strong>Testing different scenarios:</strong></p>";
        
        // Test 1: Just username
        $test1 = $db->fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
        echo "<p>Username only: " . ($test1 ? "Found ✅" : "Not found ❌") . "</p>";
        
        // Test 2: Manual MD5
        $manualHash = md5($password);
        $test2 = $db->fetchOne("SELECT * FROM users WHERE username = ? AND password = ?", [$username, $manualHash]);
        echo "<p>Manual MD5: " . ($test2 ? "Found ✅" : "Not found ❌") . "</p>";
        
        // Test 3: Check actual password in DB
        if ($test1) {
            echo "<p>DB Password: " . $test1['password'] . "</p>";
            echo "<p>Expected: " . $manualHash . "</p>";
            echo "<p>Comparison: " . ($test1['password'] === $manualHash ? "MATCH ✅" : "NO MATCH ❌") . "</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
echo "</div>";

// Step 7: Simulate actual login
echo "<div class='step'>";
echo "<h3>Step 7: Simulate Actual Login Process</h3>";

$_POST['username'] = $username;
$_POST['password'] = $password;
$_POST['login'] = true;

echo "<p>Simulating POST data:</p>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

try {
    $testUsername = trim($_POST['username']);
    $testPassword = trim($_POST['password']);
    
    echo "<p>Trimmed username: '$testUsername' (length: " . strlen($testUsername) . ")</p>";
    echo "<p>Trimmed password: '$testPassword' (length: " . strlen($testPassword) . ")</p>";
    
    $loginTest = $db->fetchOne(
        "SELECT * FROM users WHERE username = ? AND password = MD5(?)",
        [$testUsername, $testPassword]
    );
    
    if ($loginTest && in_array($loginTest['role'], ['admin', 'superadmin', 'owner'])) {
        echo "<p class='success'>✅✅✅ LOGIN WOULD SUCCEED!</p>";
        echo "<p>Session would be set with:</p>";
        echo "<pre>";
        echo "user_id: " . $loginTest['id'] . "\n";
        echo "username: " . $loginTest['username'] . "\n";
        echo "role: " . $loginTest['role'] . "\n";
        echo "</pre>";
    } else {
        if (!$loginTest) {
            echo "<p class='fail'>❌ Query returned null</p>";
        } else {
            echo "<p class='fail'>❌ Role check failed: " . $loginTest['role'] . "</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

echo "<hr>";
echo "<p><a href='developer-panel.php' style='padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px;'>→ Try Login in Developer Panel</a></p>";

echo "</body></html>";
