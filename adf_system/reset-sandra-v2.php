<?php
/**
 * CORRECTED: Reset Sandra password with CORRECT credentials
 * This fixes the credential issue in reset-sandra-hosting.php
 */

// Production hosting credentials (from config.php)
$host = 'localhost';
$masterDbName = 'adfb2574_adf';
$masterUser = 'adfb2574_adfsystem';  // CORRECT credential
$masterPassword = '@Nnoc2025';       // CORRECT credential

try {
    // Connect to master database
    $masterPdo = new PDO(
        "mysql:host=$host;dbname=$masterDbName;charset=utf8mb4",
        $masterUser,
        $masterPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $username = 'sandra';
    $plainPassword = 'admin123';
    $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);
    
    // Check if user exists
    $checkStmt = $masterPdo->prepare("SELECT id FROM users WHERE username = ?");
    $checkStmt->execute([$username]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing user
        $updateStmt = $masterPdo->prepare("
            UPDATE users 
            SET password = ?, is_active = 1
            WHERE username = ?
        ");
        $updateStmt->execute([$hashedPassword, $username]);
        
        echo "✓ Sandra UPDATED<br>";
        echo "ID: " . $existing['id'] . "<br>";
    } else {
        // Create new user
        $insertStmt = $masterPdo->prepare("
            INSERT INTO users (username, password, full_name, email, role_id, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $insertStmt->execute([
            $username,
            $hashedPassword,
            'Sandra Oktavia',
            'sandra@narayana.com',
            3  // Staff role
        ]);
        
        $userId = $masterPdo->lastInsertId();
        echo "✓ Sandra CREATED<br>";
        echo "ID: " . $userId . "<br>";
    }
    
    echo "Username: $username<br>";
    echo "Password: $plainPassword<br>";
    echo "Hashed: " . substr($hashedPassword, 0, 30) . "...<br>";
    echo "<br><strong>Try login at: <a href='login.php'>Login</a> with sandra/admin123</strong>";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
    error_log("Sandra reset error: " . $e->getMessage());
}
?>
