<?php
/**
 * Sync specific user to all business databases
 * This ensures user exists in all databases they have access to
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/business_helper.php';

// Get username from command line or default
$username = $argv[1] ?? 'puspita';

echo "Syncing user: $username\n";
echo str_repeat("=", 50) . "\n";

// Try to find user in any database (start with Bens Cafe where we know they exist)
$databases = ['adf_benscafe', 'adf_narayana_hotel', 'adf_eat_meet', 'adf_pabrik_kapal', 'adf_furniture', 'adf_karimunjawa'];
$user = null;
$sourceDb = null;

foreach ($databases as $dbName) {
    try {
        $testDb = new PDO(
            "mysql:host=localhost;dbname=$dbName;charset=utf8mb4",
            "root",
            "",
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $stmt = $testDb->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($foundUser) {
            $user = $foundUser;
            $sourceDb = $dbName;
            break;
        }
    } catch (PDOException $e) {
        // Database might not exist, continue
        continue;
    }
}

if (!$user) {
    die("❌ User '$username' not found in any database!\n");
}

echo "✅ Found user in: $sourceDb\n";
echo "Username: {$user['username']} ({$user['role']})\n";
echo "Business Access: " . ($user['business_access'] ?? 'NULL') . "\n\n";

// Get business access
$businessAccess = json_decode($user['business_access'] ?? '[]', true);

if (empty($businessAccess)) {
    die("❌ User has no business access configured!\n");
}

// Get all businesses
$allBusinesses = getAvailableBusinesses();

// Sync to each business database
foreach ($businessAccess as $bizId) {
    if (!isset($allBusinesses[$bizId])) {
        echo "⚠️  Business '$bizId' not found, skipping...\n";
        continue;
    }
    
    $bizConfig = $allBusinesses[$bizId];
    $dbName = $bizConfig['database'];
    
    echo "Syncing to: {$bizConfig['name']} (database: $dbName)...\n";
    
    try {
        // Connect to business database
        $bizDb = new PDO(
            "mysql:host=localhost;dbname=$dbName;charset=utf8mb4",
            "root",
            "",
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Check if user exists
        $checkStmt = $bizDb->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->execute([$username]);
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // Update existing user
            $updateSql = "UPDATE users SET 
                full_name = ?, 
                email = ?, 
                password = ?, 
                role = ?, 
                is_active = ?, 
                is_trial = ?, 
                trial_expires_at = ?,
                business_access = ?
                WHERE username = ?";
            $updateStmt = $bizDb->prepare($updateSql);
            $updateStmt->execute([
                $user['full_name'],
                $user['email'],
                $user['password'],
                $user['role'],
                $user['is_active'],
                $user['is_trial'],
                $user['trial_expires_at'],
                $user['business_access'],
                $username
            ]);
            echo "  ✅ Updated existing user\n";
        } else {
            // Insert new user
            $insertSql = "INSERT INTO users (
                username, full_name, email, password, role, 
                is_active, is_trial, trial_expires_at, business_access, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insertStmt = $bizDb->prepare($insertSql);
            $insertStmt->execute([
                $user['username'],
                $user['full_name'],
                $user['email'],
                $user['password'],
                $user['role'],
                $user['is_active'],
                $user['is_trial'],
                $user['trial_expires_at'],
                $user['business_access'],
                $user['created_at']
            ]);
            echo "  ✅ Created new user\n";
        }
        
        // Sync permissions if table exists
        try {
            // Delete old permissions
            $bizDb->prepare("DELETE FROM user_permissions WHERE user_id = (SELECT id FROM users WHERE username = ?)")
                  ->execute([$username]);
            
            // Get permissions from source db
            $srcDb = new PDO(
                "mysql:host=localhost;dbname=$sourceDb;charset=utf8mb4",
                "root",
                "",
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $permStmt = $srcDb->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
            $permStmt->execute([$user['id']]);
            $permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($permissions)) {
                // Insert permissions
                $userId = $bizDb->query("SELECT id FROM users WHERE username = '$username'")->fetchColumn();
                foreach ($permissions as $perm) {
                    $bizDb->prepare("INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)")
                          ->execute([$userId, $perm]);
                }
                echo "  ✅ Synced " . count($permissions) . " permissions\n";
            }
        } catch (PDOException $e) {
            // user_permissions table might not exist
            echo "  ⚠️  Could not sync permissions: " . $e->getMessage() . "\n";
        }
        
    } catch (PDOException $e) {
        echo "  ❌ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ Sync completed!\n";
echo "User '$username' should now be able to access all configured businesses.\n";
echo "\nNext steps:\n";
echo "1. Ask user to logout\n";
echo "2. Login again\n";
echo "3. Check if businesses appear in dropdown\n";
