<?php
/**
 * Developer Quick Access - Auto Login Bypass
 * Direct access to business without manual login
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

// Clear any existing session and restart
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if developer access token provided
if (!isset($_GET['dev_access'])) {
    header('Location: login.php');
    exit;
}

$devToken = base64_decode($_GET['dev_access']);

try {
    // Connect to master database
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Try to find business by database_name (exact match first, then resolved name)
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE database_name = ? AND is_active = 1");
    $stmt->execute([$devToken]);
    $business = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found, try with getDbName() mapping (local name might have been passed)
    if (!$business && function_exists('getDbName')) {
        $resolvedName = getDbName($devToken);
        if ($resolvedName !== $devToken) {
            $stmt->execute([$resolvedName]);
            $business = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($business) {
                $devToken = $resolvedName;
            }
        }
    }
    
    // Also try searching by business_code
    if (!$business) {
        $stmt2 = $pdo->prepare("SELECT * FROM businesses WHERE business_code = ? AND is_active = 1");
        $stmt2->execute([$devToken]);
        $business = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($business) {
            $devToken = $business['database_name'];
        }
    }
    
    if (!$business) {
        die("Business not found for token: " . htmlspecialchars($devToken) . ". Make sure the business exists and is active.");
    }
    
    // Use the database_name from the business record
    $bizDbName = $business['database_name'];
    
    // Verify the business database exists — auto-create if missing
    $dbCheck = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
    $dbCheck->execute([$bizDbName]);
    if ($dbCheck->rowCount() === 0) {
        // Auto-create database using DatabaseManager (supports cPanel UAPI)
        try {
            require_once __DIR__ . '/includes/DatabaseManager.php';
            $dbMgr = new DatabaseManager();
            // Pass the actual name directly (already has hosting prefix)
            $result = $dbMgr->createDatabase($bizDbName);
            
            // If DB was created, also run the business template
            $templatePath = __DIR__ . '/database/business_template.sql';
            if (file_exists($templatePath)) {
                $dbPdoNew = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
                $dbPdoNew->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $sql = file_get_contents($templatePath);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement) && strpos($statement, '--') !== 0) {
                        $dbPdoNew->exec($statement);
                    }
                }
            }
        } catch (Exception $dbCreateErr) {
            die("Database '{$bizDbName}' does not exist and auto-creation failed: " . $dbCreateErr->getMessage() . 
                "<br><br>Please create it manually in cPanel → MySQL Databases, then grant user '" . DB_USER . "' ALL PRIVILEGES on it.");
        }
    }
    
    // Connect to business database
    $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
    $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get admin or owner user from business
    $userStmt = $bizPdo->query("SELECT * FROM users WHERE role IN ('admin', 'owner') AND is_active = 1 ORDER BY id LIMIT 1");
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die("No admin user found in business database '{$bizDbName}'");
    }
    
    // Dynamically build business slug from business_name
    $activeBusinessId = strtolower(str_replace(' ', '-', $business['business_name']));
    // Also check the known mapping
    $businessIdMap = [
        'adf_benscafe' => 'bens-cafe',
        'adfb2574_Adf_Bens' => 'bens-cafe',
        'adf_narayana_hotel' => 'narayana-hotel',
        'adfb2574_narayana_hotel' => 'narayana-hotel'
    ];
    if (isset($businessIdMap[$bizDbName])) {
        $activeBusinessId = $businessIdMap[$bizDbName];
    }
    
    // Set session - bypass normal login
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['business_access'] = $user['business_access'] ?? 'all';
    $_SESSION['logged_in'] = true;
    $_SESSION['business_id'] = $business['id'];
    $_SESSION['business_code'] = $business['business_code'];
    $_SESSION['business_name'] = $business['business_name'];
    $_SESSION['database_name'] = $bizDbName;
    $_SESSION['developer_mode'] = true;
    $_SESSION['login_time'] = time();
    
    // CRITICAL: Set active_business_id so system knows which business to load
    $_SESSION['active_business_id'] = $activeBusinessId;
    
    // Log developer access
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, ip_address, new_data) VALUES (?, 'developer_access', 'businesses', ?, ?)")
            ->execute([
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                json_encode(['business' => $business['business_name'], 'database' => $bizDbName, 'as_user' => $user['username']])
            ]);
    } catch (Exception $e) {}
    
    // Redirect to dashboard
    header('Location: index.php');
    exit;
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
