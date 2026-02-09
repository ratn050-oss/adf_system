<?php
/**
 * ADF System - Quick Database Setup
 * Simple one-click setup for hosting
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get config from database.php
$config_file = __DIR__ . '/config/database.php';
if (!file_exists($config_file)) {
    die('Error: config/database.php not found!');
}

// Extract database credentials from config file
$config = file_get_contents($config_file);

// Parse database config
preg_match("/define\('DB_HOST'\s*,\s*'([^']+)'/", $config, $m);
$DB_HOST = $m[1] ?? 'localhost';

preg_match("/define\('DB_USER'\s*,\s*'([^']+)'/", $config, $m);
$DB_USER = $m[1] ?? 'root';

preg_match("/define\('DB_PASS'\s*,\s*'([^']+)'/", $config, $m);
$DB_PASS = $m[1] ?? '';

preg_match("/define\('DB_NAME'\s*,\s*'([^']+)'/", $config, $m);
$DB_NAME = $m[1] ?? 'adf_system';

$result = ['status' => 'pending', 'message' => ''];

try {
    // 1. Connect to MySQL (no database selected)
    $pdo = new PDO(
        "mysql:host=$DB_HOST;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // 2. Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // 3. Select database
    $pdo->exec("USE `$DB_NAME`");
    
    // 3a. Disable FK checks first (needed for drops)
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    
    // 3b. Drop existing tables (for fresh setup)
    $pdo->exec("DROP TABLE IF EXISTS `user_menu_permissions`");
    $pdo->exec("DROP TABLE IF EXISTS `user_preferences`");
    $pdo->exec("DROP TABLE IF EXISTS `businesses`");
    $pdo->exec("DROP TABLE IF EXISTS `users`");
    $pdo->exec("DROP TABLE IF EXISTS `roles`");
    
    // 4. Create roles table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `roles` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(50) UNIQUE NOT NULL,
        role_code VARCHAR(20) UNIQUE NOT NULL,
        description TEXT DEFAULT NULL,
        is_system_role TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 5. Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        role_id INT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        last_login DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 6. Create user_preferences table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_preferences` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        theme VARCHAR(50) DEFAULT 'dark',
        language VARCHAR(10) DEFAULT 'id',
        notifications_enabled TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 7. Create businesses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `businesses` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_code VARCHAR(30) UNIQUE NOT NULL,
        business_name VARCHAR(100) NOT NULL,
        business_type ENUM('hotel', 'restaurant', 'retail', 'manufacture', 'tourism', 'other') DEFAULT 'other',
        database_name VARCHAR(100) NOT NULL UNIQUE,
        owner_id INT NOT NULL,
        address TEXT DEFAULT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        website VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 8. Create user_menu_permissions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_menu_permissions` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        business_id INT NOT NULL,
        menu_code VARCHAR(100) NOT NULL,
        can_view TINYINT(1) DEFAULT 1,
        can_create TINYINT(1) DEFAULT 0,
        can_edit TINYINT(1) DEFAULT 0,
        can_delete TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 9. Add foreign keys
    try { $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT fk_users_role_id FOREIGN KEY (role_id) REFERENCES `roles`(id) ON DELETE RESTRICT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES `users`(id) ON DELETE SET NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `businesses` ADD CONSTRAINT businesses_ibfk_1 FOREIGN KEY (owner_id) REFERENCES `users`(id) ON DELETE RESTRICT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `user_preferences` ADD CONSTRAINT fk_user_pref_user_id FOREIGN KEY (user_id) REFERENCES `users`(id) ON DELETE CASCADE"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `user_menu_permissions` ADD CONSTRAINT fk_perm_user_id FOREIGN KEY (user_id) REFERENCES `users`(id) ON DELETE CASCADE"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `user_menu_permissions` ADD CONSTRAINT fk_perm_business_id FOREIGN KEY (business_id) REFERENCES `businesses`(id) ON DELETE CASCADE"); } catch (Exception $e) {}
    
    // 10. Insert default roles
    $pdo->exec("DELETE FROM `roles`");
    $roles = [
        ['admin', 'Admin', 'System administrator'],
        ['manager', 'Manager', 'Business manager'],
        ['staff', 'Staff', 'Regular staff'],
        ['developer', 'Developer', 'System developer']
    ];
    
    $stmt = $pdo->prepare("INSERT INTO `roles` (role_code, role_name, description) VALUES (?, ?, ?)");
    foreach ($roles as $role) {
        $stmt->execute($role);
    }
    
    // 12. Get admin role ID
    $role_result = $pdo->query("SELECT id FROM `roles` WHERE role_code = 'admin'");
    $admin_role = $role_result->fetch(PDO::FETCH_ASSOC);
    $admin_role_id = $admin_role['id'] ?? 1;
    
    // 13. Insert admin user
    $pdo->exec("DELETE FROM `users`");
    $admin_password = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO `users` (username, email, password, full_name, phone, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['admin', 'admin@adfsystem.local', $admin_password, 'Administrator', '0000000000', $admin_role_id, 1]);
    
    // 14. Get admin user ID
    $user_result = $pdo->query("SELECT id FROM `users` WHERE username = 'admin'");
    $admin_user = $user_result->fetch(PDO::FETCH_ASSOC);
    $admin_user_id = $admin_user['id'] ?? 1;
    
    // 15. Insert user preferences
    $stmt = $pdo->prepare("INSERT INTO `user_preferences` (user_id, theme, language) VALUES (?, ?, ?)");
    $stmt->execute([$admin_user_id, 'dark', 'id']);
    
    // 16. Insert default businesses
    $pdo->exec("DELETE FROM `businesses`");
    $businesses = [
        ['NARAYANAHOTEL', 'Narayana Hotel', 'hotel', 'adf_narayana_hotel', $admin_user_id],
        ['BENSCAFE', 'Bens Cafe', 'restaurant', 'adf_benscafe', $admin_user_id]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO `businesses` (business_code, business_name, business_type, database_name, owner_id) VALUES (?, ?, ?, ?, ?)");
    $business_ids = [];
    foreach ($businesses as $biz) {
        $stmt->execute($biz);
        $business_ids[] = $pdo->lastInsertId();
    }
    
    // 17. Grant all menu permissions to admin for all businesses
    $pdo->exec("DELETE FROM `user_menu_permissions`");
    $menus = ['dashboard', 'cashbook', 'divisions', 'frontdesk', 'procurement', 'sales', 'reports', 'settings', 'users'];
    
    $stmt = $pdo->prepare("INSERT INTO `user_menu_permissions` (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, 1, 1, 1, 1)");
    foreach ($business_ids as $biz_id) {
        foreach ($menus as $menu) {
            $stmt->execute([$admin_user_id, $biz_id, $menu]);
        }
    }
    
    // Re-enable FK checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    $result['status'] = 'success';
    $result['message'] = '✅ Database setup berhasil!';
    $result['details'] = [
        'database' => $DB_NAME,
        'tables' => 5,
        'admin_user' => 'admin',
        'admin_password' => 'admin123',
        'businesses' => 2,
        'permissions' => count($menus)
    ];
    
} catch (PDOException $e) {
    $result['status'] = 'error';
    $result['message'] = '❌ Error: ' . $e->getMessage();
} catch (Exception $e) {
    $result['status'] = 'error';
    $result['message'] = '❌ Error: ' . $e->getMessage();
}

// Return JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
