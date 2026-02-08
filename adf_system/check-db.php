<?php
require_once 'config/config.php';
echo "<h2>Master Database Check</h2>";
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    echo "✅ Connected to: " . DB_NAME . "<br>";
    
    // Check columns
    echo "<h3>Users Table Columns:</h3>";
    $cols = $pdo->query("DESCRIBE users")->fetchAll();
    foreach ($cols as $c) {
        echo "- " . $c['Field'] . " (" . $c['Type'] . ")<br>";
    }
    
    // Check if role_id exists
    $roleIdExists = false;
    foreach ($cols as $c) {
        if ($c['Field'] === 'role_id') {
            $roleIdExists = true;
            break;
        }
    }
    
    echo "<h3>Role_ID Status:</h3>";
    if ($roleIdExists) {
        echo "✅ role_id column EXISTS<br>";
    } else {
        echo "❌ role_id column MISSING - need to add!<br>";
        echo "<pre>ALTER TABLE users ADD COLUMN role_id INT DEFAULT 1 AFTER password;</pre>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
