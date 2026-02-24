<?php
/**
 * Quick Setup: Run business template on existing empty database
 * Fixed: strips SQL comment lines before splitting to avoid skipping CREATE TABLE statements
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$targetDb = $_GET['db'] ?? 'adfb2574_demo';
$targetDb = preg_replace('/[^a-zA-Z0-9_]/', '', $targetDb);
$run = isset($_GET['run']);
$results = [];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $check = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$targetDb}'");
    if ($check->rowCount() === 0) {
        die("Database '{$targetDb}' does not exist!");
    }
    
    $dbPdo = new PDO("mysql:host=" . DB_HOST . ";dbname={$targetDb}", DB_USER, DB_PASS);
    $dbPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $tables = $dbPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $results[] = ['info', "Database '{$targetDb}' has " . count($tables) . " existing tables."];
    
    if ($run) {
        // ====================================================
        // STEP 1: Run business_template.sql
        // ====================================================
        $templatePath = __DIR__ . '/database/business_template.sql';
        if (!file_exists($templatePath)) {
            die("Template file not found: database/business_template.sql");
        }
        
        $sql = file_get_contents($templatePath);
        
        // FIX: Strip comment lines FIRST, then split by semicolons
        // Old code had: explode(';', $sql) then skip if starts with '--'
        // Problem: after explode, CREATE TABLE chunks START with comment lines, so ALL were skipped!
        $lines = explode("\n", $sql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
            $cleanLines[] = $line;
        }
        $cleanSql = implode("\n", $cleanLines);
        $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
        
        $executed = 0;
        $errors = [];
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $dbPdo->exec($stmt);
                    $executed++;
                } catch (PDOException $e) {
                    $errors[] = $e->getMessage() . ' | ' . substr($stmt, 0, 100);
                }
            }
        }
        $results[] = ['success', "Template: {$executed} statements executed."];

        // ====================================================
        // STEP 2: Create extra system tables (users, settings, etc)
        // ====================================================
        $extraSql = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(20),
                role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
                business_access TEXT,
                is_active TINYINT(1) DEFAULT 1,
                last_login DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_username (username),
                INDEX idx_role (role),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                setting_group VARCHAR(50) DEFAULT 'general',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_key (setting_key),
                INDEX idx_group (setting_group)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS cash_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_name VARCHAR(100) NOT NULL,
                account_type ENUM('cash','bank','e-wallet','petty_cash') DEFAULT 'cash',
                balance DECIMAL(15,2) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (account_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_code VARCHAR(30) UNIQUE NOT NULL,
                role_name VARCHAR(100) NOT NULL,
                permissions TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action_type VARCHAR(50),
                table_name VARCHAR(100),
                record_id INT,
                old_data LONGTEXT,
                new_data LONGTEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_action (action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        
        $extraOk = 0;
        foreach ($extraSql as $stmt) {
            try { $dbPdo->exec($stmt); $extraOk++; } 
            catch (PDOException $e) { $errors[] = 'Extra: ' . $e->getMessage(); }
        }
        $results[] = ['success', "Extra system tables: {$extraOk} created (users, settings, cash_accounts, roles, audit_logs)."];

        // ====================================================
        // STEP 3: Default admin user
        // ====================================================
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($cnt == 0) {
                $hash = password_hash('admin123', PASSWORD_DEFAULT);
                $dbPdo->prepare("INSERT INTO users (username, password, full_name, role, is_active) VALUES (?,?,?,?,?)")
                    ->execute(['admin', $hash, 'Administrator', 'admin', 1]);
                $results[] = ['success', "Default admin created (user: admin / pass: admin123)."];
            }
        } catch (Exception $e) { $errors[] = 'Admin: ' . $e->getMessage(); }

        // ====================================================
        // STEP 4: Default settings
        // ====================================================
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
            if ($cnt == 0) {
                $ins = $dbPdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?,?,?)");
                foreach ([
                    ['company_name', 'New Business', 'general'],
                    ['currency', 'IDR', 'general'],
                    ['timezone', 'Asia/Jakarta', 'general'],
                    ['language', 'id', 'general'],
                ] as $s) { $ins->execute($s); }
                $results[] = ['success', "Default settings inserted."];
            }
        } catch (Exception $e) { $errors[] = 'Settings: ' . $e->getMessage(); }

        // Show errors
        if (!empty($errors)) {
            $results[] = ['warning', "Warnings (" . count($errors) . "): " . implode(' | ', array_slice($errors, 0, 5))];
        }
        
        // Final table count
        $tablesAfter = $dbPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $results[] = ['success', "Tables now (" . count($tablesAfter) . "): " . implode(', ', $tablesAfter)];
        $results[] = ['success', "<strong>DONE!</strong> Database '{$targetDb}' is ready to use."];
        
    } else {
        if (count($tables) > 0) {
            $results[] = ['info', "Existing: " . implode(', ', $tables)];
        }
        $results[] = ['action', "<a href='?db={$targetDb}&run=1' style='display:inline-block;background:#16a34a;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:1.1rem;'>▶ RUN SETUP on {$targetDb}</a>"];
    }
    
} catch (PDOException $e) {
    $results[] = ['error', "ERROR: " . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html><head><title>Quick DB Setup</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:2rem;max-width:750px;margin:auto;background:#f1f5f9;color:#1e293b;}
h2{margin-bottom:1rem;}
.r{padding:0.75rem 1rem;margin:0.4rem 0;border-radius:8px;font-size:0.9rem;}
.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.warning{background:#fef9c3;color:#854d0e;border:1px solid #fde68a;}
.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.action{background:#fff;border:1px solid #e2e8f0;text-align:center;padding:1.5rem;}
hr{border:none;border-top:1px solid #e2e8f0;margin:1rem 0;}
a.db{color:#3b82f6;}
</style>
</head><body>
<h2>⚡ Quick Database Setup: <?= htmlspecialchars($targetDb) ?></h2>
<?php foreach($results as $r): ?>
<div class="r <?= $r[0] ?>"><?= $r[1] ?></div>
<?php endforeach; ?>
<hr>
<p><small>Other databases: 
<a class="db" href="?db=adfb2574_demo">adfb2574_demo</a> | 
<a class="db" href="?db=adfb2574_benscafe">adfb2574_benscafe</a> |
<a class="db" href="?db=adfb2574_narayana_hotel">adfb2574_narayana_hotel</a>
</small></p>
</body></html>
