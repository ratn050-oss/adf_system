<?php
/**
 * ADF System - Setup Wizard
 * Standalone setup tool
 */

define('APP_ACCESS', true);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load minimal config
$config_path = __DIR__ . '/config/database.php';
if (!file_exists($config_path)) {
    die('❌ config/database.php not found!');
}

// Read database config
$config_content = file_get_contents($config_path);
preg_match('/define\([\'"]DB_HOST[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\)/', $config_content, $m);
$db_host = $m[1] ?? 'localhost';

preg_match('/define\([\'"]DB_USER[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\)/', $config_content, $m);
$db_user = $m[1] ?? 'root';

preg_match('/define\([\'"]DB_PASS[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/', $config_content, $m);
$db_pass = $m[1] ?? '';

preg_match('/define\([\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\)/', $config_content, $m);
$db_name = $m[1] ?? 'adf_system';

// If form submitted, process setup
$message = '';
$status = 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? $db_host;
    $db_user = $_POST['db_user'] ?? $db_user;
    $db_pass = $_POST['db_pass'] ?? $db_pass;
    $db_name = $_POST['db_name'] ?? $db_name;
    
    try {
        // Test connection
        $pdo = new PDO(
            "mysql:host={$db_host};charset=utf8mb4",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // USE database
        $pdo->exec("USE {$db_name}");
        
        // Create tables
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        $tables = [
            'roles' => "CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_name VARCHAR(50) UNIQUE NOT NULL,
                role_code VARCHAR(20) UNIQUE NOT NULL,
                description TEXT DEFAULT NULL,
                is_system_role TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            'users' => "CREATE TABLE IF NOT EXISTS users (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            'user_preferences' => "CREATE TABLE IF NOT EXISTS user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                theme VARCHAR(50) DEFAULT 'dark',
                language VARCHAR(10) DEFAULT 'id',
                notifications_enabled TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            'businesses' => "CREATE TABLE IF NOT EXISTS businesses (
                id VARCHAR(50) PRIMARY KEY,
                business_code VARCHAR(50) UNIQUE NOT NULL,
                business_name VARCHAR(100) NOT NULL,
                business_type VARCHAR(50) DEFAULT NULL,
                address TEXT DEFAULT NULL,
                phone VARCHAR(20) DEFAULT NULL,
                email VARCHAR(100) DEFAULT NULL,
                website VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            'user_menu_permissions' => "CREATE TABLE IF NOT EXISTS user_menu_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                business_id VARCHAR(50) NOT NULL,
                menu_code VARCHAR(100) NOT NULL,
                can_view TINYINT(1) DEFAULT 1,
                can_create TINYINT(1) DEFAULT 0,
                can_edit TINYINT(1) DEFAULT 0,
                can_delete TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        foreach ($tables as $name => $sql) {
            $pdo->exec($sql);
        }
        
        // Add foreign keys after all tables created
        try {
            $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT");
        } catch (Exception $e) {
            // FK might already exist, ignore
        }
        
        try {
            $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        } catch (Exception $e) {
            // FK might already exist, ignore
        }
        
        try {
            $pdo->exec("ALTER TABLE user_preferences ADD CONSTRAINT fk_user_pref_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        } catch (Exception $e) {
            // FK might already exist, ignore
        }
        
        try {
            $pdo->exec("ALTER TABLE user_menu_permissions ADD CONSTRAINT fk_perm_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        } catch (Exception $e) {
            // FK might already exist, ignore
        }
        
        try {
            $pdo->exec("ALTER TABLE user_menu_permissions ADD CONSTRAINT fk_perm_business_id FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE");
        } catch (Exception $e) {
            // FK might already exist, ignore
        }
        
        // Insert roles
        $roles_data = [
            ['admin', 'Admin', 'System administrator'],
            ['manager', 'Manager', 'Business manager'],
            ['staff', 'Staff', 'Regular staff'],
            ['developer', 'Developer', 'System developer']
        ];
        
        $pdo->exec("DELETE FROM roles");
        $stmt = $pdo->prepare("INSERT INTO roles (role_code, role_name, description) VALUES (?, ?, ?)");
        foreach ($roles_data as $role) {
            $stmt->execute($role);
        }
        
        // Get admin role ID
        $role = $pdo->query("SELECT id FROM roles WHERE role_code = 'admin'")->fetch();
        $admin_role_id = $role['id'];
        
        // Insert admin user
        $pdo->exec("DELETE FROM users");
        $admin_password = password_hash('admin123', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, phone, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@adfsystem.local', $admin_password, 'Administrator', '0000000000', $admin_role_id, 1]);
        
        $admin = $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetch();
        $admin_id = $admin['id'];
        
        // Insert user preferences
        $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, theme, language) VALUES (?, ?, ?)");
        $stmt->execute([$admin_id, 'dark', 'id']);
        
        // Insert businesses
        $pdo->exec("DELETE FROM businesses");
        $businesses = [
            ['narayana-hotel', 'NARAYANAHOTEL', 'Narayana Hotel', 'hotel'],
            ['bens-cafe', 'BENSCAFE', 'Bens Cafe', 'cafe']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO businesses (id, business_code, business_name, business_type) VALUES (?, ?, ?, ?)");
        foreach ($businesses as $biz) {
            $stmt->execute($biz);
        }
        
        // Grant permissions
        $menus = ['dashboard', 'cashbook', 'divisions', 'frontdesk', 'procurement', 'sales', 'reports', 'settings', 'users'];
        $pdo->exec("DELETE FROM user_menu_permissions");
        $stmt = $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, 1, 1, 1, 1)");
        
        foreach ($businesses as $biz) {
            $biz_id = $biz[0];
            foreach ($menus as $menu) {
                $stmt->execute([$admin_id, $biz_id, $menu]);
            }
        }
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        
        $status = 'success';
        $message = "✅ Setup berhasil! Database, tables, dan admin user telah dibuat.";
        
    } catch (Exception $e) {
        $status = 'error';
        $message = "❌ Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADF System - Setup Wizard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 500px; width: 100%; padding: 40px; }
        h1 { color: #333; margin-bottom: 10px; text-align: center; }
        .subtitle { text-align: center; color: #666; margin-bottom: 30px; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; transition: border-color 0.3s; }
        input:focus, select:focus { outline: none; border-color: #667eea; }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; font-weight: 600; cursor: pointer; font-size: 16px; transition: background 0.3s; }
        button:hover { background: #764ba2; }
        .message { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info-box { background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; color: #0c3d68; border-left: 4px solid #2196F3; }
        .success-item { margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 ADF System Setup</h1>
        <p class="subtitle">Database & Table Setup Wizard</p>
        
        <?php if ($status === 'success'): ?>
            <div class="message success">
                <?php echo $message; ?>
                <div class="success-item"><strong>✅ Admin User Created:</strong></div>
                <div style="margin-left: 10px;">
                    Username: <code>admin</code><br>
                    Password: <code>admin123</code>
                </div>
                <div class="success-item" style="margin-top: 15px;"><a href="login.php" style="color: #155724; text-decoration: underline;">→ Go to Login</a></div>
            </div>
        <?php elseif ($status === 'error'): ?>
            <div class="message error"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="info-box">
            📌 <strong>Database Configuration:</strong> Settings dibaca dari <code>config/database.php</code>. Anda bisa custom jika perlu.
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label for="db_host">Database Host</label>
                <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($db_host); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="db_user">Database User</label>
                <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($db_user); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="db_pass">Database Password</label>
                <input type="password" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>">
            </div>
            
            <div class="form-group">
                <label for="db_name">Database Name</label>
                <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($db_name); ?>" required>
            </div>
            
            <button type="submit">▶ Start Setup</button>
        </form>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #999;">
            ADF System v2.0 • Setup Wizard<br>
            After setup, delete this file for security
        </div>
    </div>
</body>
</html>
