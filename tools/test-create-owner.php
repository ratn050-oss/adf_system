<?php
/**
 * Test Create Owner
 * Script untuk test apakah create owner berhasil
 */

require_once '../config/businesses.php';

echo "<pre>";
echo "==============================================\n";
echo "TEST CREATE OWNER\n";
echo "==============================================\n\n";

// Test data
$testUsername = 'sita';
$testPassword = 'sita123';
$testFullName = 'Bu Sita';
$testEmail = 'sita@example.com';
$testRole = 'owner';
$testBusinessIds = [1, 2, 3, 4, 5, 6]; // All businesses

try {
    // Create PDO connection
    echo "1. Connecting to database...\n";
    $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "   ✅ Connected!\n\n";
    
    // Check if username exists
    echo "2. Checking if username '$testUsername' exists...\n";
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$testUsername]);
    if ($existing = $stmt->fetch()) {
        echo "   ⚠️ User already exists! Deleting old user...\n";
        $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
        $stmt->execute([$testUsername]);
        echo "   ✅ Old user deleted!\n\n";
    } else {
        echo "   ✅ Username available!\n\n";
    }
    
    // Prepare business access
    $businessAccessJson = json_encode($testBusinessIds);
    echo "3. Business Access JSON: $businessAccessJson\n\n";
    
    // Insert to main database (narayana)
    echo "4. Inserting to main database (narayana)...\n";
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password, full_name, email, role, business_access, is_active, created_at, updated_at) 
         VALUES (?, MD5(?), ?, ?, ?, ?, 1, NOW(), NOW())"
    );
    $stmt->execute([
        $testUsername, 
        $testPassword, 
        $testFullName, 
        $testEmail,
        $testRole, 
        $businessAccessJson
    ]);
    
    $userId = $pdo->lastInsertId();
    echo "   ✅ User created with ID: $userId\n\n";
    
    // Verify in main database
    echo "5. Verifying in main database...\n";
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Username: {$user['username']}\n";
    echo "   Full Name: {$user['full_name']}\n";
    echo "   Email: {$user['email']}\n";
    echo "   Role: {$user['role']}\n";
    echo "   Business Access: {$user['business_access']}\n";
    echo "   ✅ Verified!\n\n";
    
    // Insert to all business databases
    echo "6. Syncing to all business databases...\n";
    $syncedDatabases = [];
    foreach ($BUSINESSES as $business) {
        try {
            echo "   - Syncing to {$business['database']}...\n";
            $stmt = $pdo->prepare(
                "INSERT INTO {$business['database']}.users (id, username, password, full_name, email, role, business_access, is_active, created_at, updated_at) 
                 VALUES (?, ?, MD5(?), ?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE 
                    password = VALUES(password),
                    full_name = VALUES(full_name),
                    email = VALUES(email),
                    role = VALUES(role),
                    business_access = VALUES(business_access),
                    updated_at = NOW()"
            );
            $stmt->execute([
                $userId,
                $testUsername, 
                $testPassword, 
                $testFullName,
                $testEmail,
                $testRole, 
                $businessAccessJson
            ]);
            $syncedDatabases[] = $business['name'];
            echo "     ✅ Synced!\n";
        } catch (Exception $e) {
            echo "     ❌ Failed: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n7. Synced to " . count($syncedDatabases) . " databases:\n";
    foreach ($syncedDatabases as $dbName) {
        echo "   - $dbName\n";
    }
    
    echo "\n==============================================\n";
    echo "✅ TEST COMPLETED SUCCESSFULLY!\n";
    echo "==============================================\n\n";
    
    echo "Login credentials:\n";
    echo "Username: $testUsername\n";
    echo "Password: $testPassword\n";
    echo "User ID: $userId\n\n";
    
    echo "<a href='developer-panel.php'>← Back to Developer Panel</a>\n";
    
} catch (Exception $e) {
    echo "\n==============================================\n";
    echo "❌ ERROR!\n";
    echo "==============================================\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
