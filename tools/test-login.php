<?php
/**
 * Test Login Developer Panel
 */
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

echo "<h1>Test Login Developer Panel</h1>";
echo "<pre>";

$username = 'admin';
$password = 'admin';

echo "Testing login with username: $username, password: $password\n\n";

try {
    $db = Database::getInstance();
    echo "✅ Database connected\n\n";
    
    // Test query
    $sql = "SELECT * FROM users WHERE username = ? AND password = MD5(?) AND is_active = 1";
    echo "Query: $sql\n";
    echo "Params: ['$username', '$password']\n\n";
    
    $user = $db->fetchOne($sql, [$username, $password]);
    
    if ($user) {
        echo "✅ USER FOUND!\n\n";
        echo "User data:\n";
        print_r($user);
        
        echo "\n\nChecking role...\n";
        if (in_array($user['role'], ['admin', 'superadmin', 'owner'])) {
            echo "✅ Role is valid: {$user['role']}\n";
            echo "\n✅ LOGIN SHOULD WORK!\n";
            
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
            echo "\nSession set:\n";
            print_r($_SESSION);
            
        } else {
            echo "❌ Role not valid: {$user['role']}\n";
        }
    } else {
        echo "❌ USER NOT FOUND!\n";
        echo "Query returned no results.\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";

echo "<hr>";
echo "<a href='developer-panel.php'>Go to Developer Panel</a>";
?>
